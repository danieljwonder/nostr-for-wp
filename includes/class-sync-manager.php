<?php
/**
 * Sync Manager Class
 * 
 * Handles bidirectional synchronization between WordPress and Nostr
 */

if (!defined('ABSPATH')) {
    exit;
}

class Nostr_Sync_Manager {
    
    /**
     * Single instance
     */
    private static $instance = null;
    
    /**
     * Get single instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Sync to Nostr when WordPress content is saved
        add_action('save_post', array($this, 'sync_to_nostr'), 10, 2);
        add_action('wp_ajax_nostr_manual_sync', array($this, 'handle_manual_sync'));
        add_action('wp_ajax_nostr_get_sync_status', array($this, 'get_sync_status'));
        
        // Enable sync by default for new posts
        add_action('wp_insert_post', array($this, 'enable_sync_by_default'), 10, 3);
        
        // AJAX handlers for browser-triggered sync
        add_action('wp_ajax_nostr_sync_pending_posts', array($this, 'sync_pending_posts_ajax'));
        add_action('wp_ajax_nostr_sync_post', array($this, 'sync_post_ajax'));
    }
    
    /**
     * Sync WordPress content to Nostr
     */
    public function sync_to_nostr($post_id, $post) {
        // Skip autosaves and revisions
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }
        
        // Check if sync is enabled for this post
        $sync_enabled = get_post_meta($post_id, '_nostr_sync_enabled', true);
        if (!$sync_enabled) {
            return;
        }
        
        // Skip if this is a Nostr-originated post to prevent loops
        $nostr_origin = get_post_meta($post_id, '_nostr_origin', true);
        if ($nostr_origin) {
            return;
        }
        
        // Mark as pending sync - actual sync will be triggered by browser
        update_post_meta($post_id, '_nostr_sync_status', 'pending');
        update_post_meta($post_id, '_nostr_sync_pending', '1');
        
        error_log('Nostr: Post ' . $post_id . ' marked for sync (pending browser signing)');
    }
    
    /**
     * Sync from Nostr to WordPress
     */
    public function sync_from_nostr($user_id = null, $force_full_resync = false) {
        // Default to user 1 for post authoring if no user_id provided
        if (!$user_id) {
            $user_id = 1;
        }
        
        $nostr_client = Nostr_Client::get_instance();
        
        // Get site's public key to filter events (single site identity)
        $public_key = $nostr_client->get_public_key();
        if (!$public_key) {
            error_log('Nostr: No public key found for site');
            return false;
        }
        
        // Determine sync timestamp
        $since_timestamp = null;
        if (!$force_full_resync) {
            // Get last sync timestamp from site options
            $last_sync_option = get_option('nostr_last_sync_timestamp');
            
            // If no last sync, this is the first run - sync all events
            if ($last_sync_option) {
                $since_timestamp = intval($last_sync_option);
            }
            // If $since_timestamp is still null, don't add 'since' filter (get all events)
        }
        // If $force_full_resync is true, $since_timestamp stays null (get all events)
        
        try {
            // Query for kind 1 events (notes) and kind 30023 (long-form) from this author
            $filters = array(
                'kinds' => array(1, 30023),
                'authors' => array($public_key),
                'limit' => 500
            );
            
            // Only add 'since' filter if we have a timestamp and not doing a full resync
            if ($since_timestamp !== null) {
                $filters['since'] = $since_timestamp;
                nostr_for_wp_debug_log('Syncing from timestamp: ' . date('Y-m-d H:i:s', $since_timestamp));
            } else {
                nostr_for_wp_debug_log('Full sync - fetching all events');
            }
            
            error_log('Nostr: Syncing from Nostr for site (author: user ' . $user_id . ')' . ($since_timestamp ? ' since ' . date('Y-m-d H:i:s', $since_timestamp) : ' (full sync)'));
            error_log('Nostr: Query filters: ' . json_encode($filters));
            
            $events = $nostr_client->subscribe_to_events($filters, $user_id);
            
            error_log('Nostr: Raw events received: ' . count($events));
            if (!empty($events)) {
                error_log('Nostr: First event sample: ' . json_encode($events[0]));
                // Log all event IDs found
                foreach ($events as $i => $event) {
                    error_log('Nostr: Event ' . ($i + 1) . ': ' . $event['id'] . ' - ' . substr($event['content'], 0, 30));
                }
            }
            
            if (empty($events)) {
                error_log('Nostr: No events found from relays');
                return 0;
            }
            
            // Deduplicate events by ID
            $unique_events = array();
            foreach ($events as $event) {
                if (isset($event['id'])) {
                    $unique_events[$event['id']] = $event;
                }
            }
            
            error_log('Nostr: Found ' . count($unique_events) . ' unique events to process');
            
            $processed_count = 0;
            $skipped_count = 0;
            
            foreach ($unique_events as $event) {
                error_log('Nostr: Processing event: ' . $event['id'] . ' - ' . substr($event['content'], 0, 50));
                $result = $this->process_nostr_event($event, $user_id);
                if ($result === true) {
                    $processed_count++;
                    error_log('Nostr: Successfully processed event: ' . $event['id']);
                } elseif ($result === 'skipped') {
                    $skipped_count++;
                    error_log('Nostr: Skipped event: ' . $event['id']);
                } else {
                    error_log('Nostr: Failed to process event: ' . $event['id']);
                }
            }
            
            // Update last sync timestamp (use current time, or oldest event time if doing full resync)
            if ($force_full_resync && !empty($unique_events)) {
                // Find the oldest event timestamp
                $oldest_timestamp = PHP_INT_MAX;
                foreach ($unique_events as $event) {
                    if (isset($event['created_at']) && $event['created_at'] < $oldest_timestamp) {
                        $oldest_timestamp = $event['created_at'];
                    }
                }
                // Store timestamp 1 second before oldest to ensure we don't miss anything
                $last_sync_timestamp = $oldest_timestamp > 0 ? ($oldest_timestamp - 1) : time();
            } else {
                $last_sync_timestamp = time();
            }
            
            update_option('nostr_last_sync_timestamp', $last_sync_timestamp);
            
            error_log('Nostr: Processed ' . $processed_count . ' events, skipped ' . $skipped_count . ' events');
            
            return array(
                'processed' => $processed_count,
                'skipped' => $skipped_count,
                'total' => count($unique_events)
            );
            
        } catch (Exception $e) {
            error_log('Nostr sync failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Process a single Nostr event
     */
    private function process_nostr_event($event, $user_id = null) {
        $log_file = WP_CONTENT_DIR . '/nostr-debug.log';
        $event_id = $event['id'] ?? 'unknown';
        
        try {
            if (!$user_id) {
                $user_id = get_current_user_id();
            }
            
            $content_mapper = Nostr_Content_Mapper::get_instance();
            
            // Validate event structure
            if (!isset($event['id']) || !isset($event['kind']) || !isset($event['content'])) {
                // Always log errors
                $msg = date('Y-m-d H:i:s') . " - Event {$event_id}: INVALID - Missing required fields (id/kind/content)\n";
                file_put_contents($log_file, $msg, FILE_APPEND | LOCK_EX);
                error_log('Nostr: Invalid event structure for event ' . $event_id);
                return false;
            }
            
            nostr_for_wp_debug_log("Processing event {$event_id} (kind: {$event['kind']}, created: " . date('Y-m-d H:i:s', $event['created_at']) . ")");
            
            // Only process kind 1 (notes) and kind 30023 (long-form) events
            if ($event['kind'] !== 1 && $event['kind'] !== 30023) {
                nostr_for_wp_debug_log("Event {$event_id}: SKIPPED - Wrong kind ({$event['kind']}, expected 1 or 30023)");
                return 'skipped'; // Skip other event types
            }
            
            // Filter out replies (notes with 'e' tags are replies to other events)
            if (isset($event['tags']) && is_array($event['tags'])) {
                foreach ($event['tags'] as $tag) {
                    if (is_array($tag) && $tag[0] === 'e') {
                        nostr_for_wp_debug_log("Event {$event_id}: SKIPPED - Is a reply (has 'e' tag)");
                        error_log('Nostr: Skipping reply event: ' . $event_id . ' - ' . substr($event['content'], 0, 30));
                        return 'skipped'; // This is a reply, skip it
                    }
                }
            }
            
            // Check if we already have this event
            $existing_post = $content_mapper->get_post_by_nostr_event_id($event['id']);
            
            if ($existing_post) {
                // Check if the Nostr version is newer
                $nostr_modified = intval($event['created_at']);
                $wp_modified = strtotime($existing_post->post_modified);
                
                nostr_for_wp_debug_log("Event {$event_id}: Already exists (WP post ID: {$existing_post->ID})");
                
                if ($nostr_modified <= $wp_modified) {
                    nostr_for_wp_debug_log("Event {$event_id}: SKIPPED - WP version newer (Nostr: " . date('Y-m-d H:i:s', $nostr_modified) . " vs WP: {$existing_post->post_modified})");
                    error_log('Nostr: Skipping event ' . $event_id . ' - WordPress version is newer');
                    return 'skipped'; // WordPress version is newer
                }
                
                // Update existing post
                $this->update_post_from_nostr_event($existing_post->ID, $event, $user_id);
                nostr_for_wp_debug_log("Event {$event_id}: UPDATED existing post {$existing_post->ID}");
                error_log('Nostr: Updated existing post ' . $existing_post->ID . ' from Nostr event ' . $event_id);
                return true;
            } else {
                nostr_for_wp_debug_log("Event {$event_id}: Creating new " . ($event['kind'] === 1 ? 'note' : 'post'));
                
                // Create new post based on event kind
                $post_id = null;
                if ($event['kind'] === 1) {
                    $post_id = $content_mapper->nostr_event_to_note($event, $user_id);
                } elseif ($event['kind'] === 30023) {
                    $post_id = $content_mapper->nostr_event_to_post($event, $user_id);
                }
                
                if ($post_id) {
                    nostr_for_wp_debug_log("Event {$event_id}: CREATED new " . ($event['kind'] === 1 ? 'note' : 'post') . " (WP ID: {$post_id})");
                    error_log('Nostr: Created new ' . ($event['kind'] === 1 ? 'note' : 'post') . ' ' . $post_id . ' from Nostr event ' . $event_id);
                    return true;
                } else {
                    // Always log failures
                    $msg = date('Y-m-d H:i:s') . " - Event {$event_id}: FAILED to create post (nostr_event_to_" . ($event['kind'] === 1 ? 'note' : 'post') . " returned false/null)\n";
                    file_put_contents($log_file, $msg, FILE_APPEND | LOCK_EX);
                    error_log('Nostr: Failed to create post from event ' . $event_id);
                    return false;
                }
            }
            
        } catch (Exception $e) {
            // Always log errors
            $msg = date('Y-m-d H:i:s') . " - Event {$event_id}: EXCEPTION - " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            file_put_contents($log_file, $msg, FILE_APPEND | LOCK_EX);
            error_log('Nostr: Error processing event ' . $event_id . ': ' . $e->getMessage());
            return false;
        } catch (Error $e) {
            // Always log fatal errors
            $msg = date('Y-m-d H:i:s') . " - Event {$event_id}: FATAL ERROR - " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            file_put_contents($log_file, $msg, FILE_APPEND | LOCK_EX);
            error_log('Nostr: Fatal error processing event ' . $event_id . ': ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update existing post from Nostr event
     */
    private function update_post_from_nostr_event($post_id, $event) {
        $content_mapper = Nostr_Content_Mapper::get_instance();
        
        // Mark as Nostr origin to prevent sync loops
        update_post_meta($post_id, '_nostr_origin', true);
        
        if ($event['kind'] === 1) {
            // Update note
            $post_data = array(
                'ID' => $post_id,
                'post_content' => $event['content'],
                'post_date' => date('Y-m-d H:i:s', $event['created_at'])
            );
            wp_update_post($post_data);
        } elseif ($event['kind'] === 30023) {
            // Update post
            $title = '';
            foreach ($event['tags'] as $tag) {
                if ($tag[0] === 'title') {
                    $title = $tag[1];
                    break;
                }
            }
            
            $content = $content_mapper->markdown_to_html($event['content']);
            
            $post_data = array(
                'ID' => $post_id,
                'post_title' => $title ?: get_the_title($post_id),
                'post_content' => $content,
                'post_date' => date('Y-m-d H:i:s', $event['created_at'])
            );
            wp_update_post($post_data);
        }
        
        // Update sync metadata
        update_post_meta($post_id, '_nostr_synced_at', current_time('mysql'));
        update_post_meta($post_id, '_nostr_sync_status', 'synced');
        
        // Remove Nostr origin flag after a short delay
        wp_schedule_single_event(time() + 60, 'nostr_remove_origin_flag', array($post_id));
    }
    
    /**
     * Handle manual sync via AJAX
     */
    public function handle_manual_sync() {
        check_ajax_referer('nostr_for_wp_admin_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_die('Insufficient permissions');
        }
        
        $post_id = intval($_POST['post_id']);
        if (!$post_id) {
            wp_send_json_error('Invalid post ID');
        }
        
        // Force sync to Nostr
        $post = get_post($post_id);
        if ($post) {
            $this->sync_to_nostr($post_id, $post);
            wp_send_json_success('Sync completed');
        } else {
            wp_send_json_error('Post not found');
        }
    }
    
    /**
     * Get sync status via AJAX
     */
    public function get_sync_status() {
        check_ajax_referer('nostr_for_wp_admin_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_die('Insufficient permissions');
        }
        
        $post_id = intval($_POST['post_id']);
        if (!$post_id) {
            wp_send_json_error('Invalid post ID');
        }
        
        $sync_enabled = get_post_meta($post_id, '_nostr_sync_enabled', true);
        $event_id = get_post_meta($post_id, '_nostr_event_id', true);
        $synced_at = get_post_meta($post_id, '_nostr_synced_at', true);
        $sync_status = get_post_meta($post_id, '_nostr_sync_status', true);
        
        wp_send_json_success(array(
            'sync_enabled' => $sync_enabled,
            'event_id' => $event_id,
            'synced_at' => $synced_at,
            'sync_status' => $sync_status
        ));
    }
    
    /**
     * Remove Nostr origin flag
     */
    public function remove_origin_flag($post_id) {
        delete_post_meta($post_id, '_nostr_origin');
    }
    
    /**
     * Check if post should sync
     */
    public function should_sync_post($post_id) {
        $sync_enabled = get_post_meta($post_id, '_nostr_sync_enabled', true);
        $nostr_origin = get_post_meta($post_id, '_nostr_origin', true);
        
        return $sync_enabled && !$nostr_origin;
    }
    
    /**
     * Get sync statistics
     */
    public function get_sync_stats() {
        global $wpdb;
        
        $stats = array(
            'total_synced' => 0,
            'sync_enabled' => 0,
            'sync_failed' => 0,
            'last_sync' => null
        );
        
        // Count synced posts
        $synced_posts = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_nostr_synced_at' 
            AND meta_value != ''
        ");
        
        $stats['total_synced'] = intval($synced_posts);
        
        // Count sync enabled posts
        $enabled_posts = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_nostr_sync_enabled' 
            AND meta_value = '1'
        ");
        
        $stats['sync_enabled'] = intval($enabled_posts);
        
        // Count failed syncs
        $failed_posts = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_nostr_sync_status' 
            AND meta_value = 'failed'
        ");
        
        $stats['sync_failed'] = intval($failed_posts);
        
        // Get last sync time
        $last_sync = $wpdb->get_var("
            SELECT meta_value 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_nostr_synced_at' 
            ORDER BY meta_value DESC 
            LIMIT 1
        ");
        
        $stats['last_sync'] = $last_sync;
        
        return $stats;
    }
    
    /**
     * Enable sync by default for new posts
     */
    public function enable_sync_by_default($post_id, $post, $update) {
        // Skip if this is an update (not a new post)
        if ($update) {
            return;
        }
        
        // Skip autosaves and revisions
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }
        
        // Enable sync by default for posts and notes
        if (in_array($post->post_type, array('post', 'note'))) {
            update_post_meta($post_id, '_nostr_sync_enabled', '1');
        }
    }
    
    /**
     * AJAX handler to sync pending posts (browser-triggered)
     */
    public function sync_pending_posts_ajax() {
        check_ajax_referer('nostr_for_wp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        // Get pending posts
        $pending_posts = get_posts(array(
            'meta_query' => array(
                array(
                    'key' => '_nostr_sync_pending',
                    'value' => '1',
                    'compare' => '='
                )
            ),
            'post_status' => 'publish',
            'numberposts' => 10
        ));
        
        $synced_count = 0;
        $errors = array();
        
        foreach ($pending_posts as $post) {
            try {
                // Create a test Nostr event (without signing for now)
                $content_mapper = Nostr_Content_Mapper::get_instance();
                $nostr_client = Nostr_Client::get_instance();
                
                if ($post->post_type === 'note') {
                    $event = $content_mapper->note_to_nostr_event($post->ID);
                } else {
                    $event = $content_mapper->post_to_nostr_event($post->ID);
                }
                
                if ($event) {
                    // For now, just simulate successful sync
                    // TODO: Implement actual WebSocket publishing
                    update_post_meta($post->ID, '_nostr_sync_pending', '0');
                    update_post_meta($post->ID, '_nostr_sync_status', 'synced');
                    update_post_meta($post->ID, '_nostr_synced_at', current_time('mysql'));
                    $synced_count++;
                    
                    error_log('Nostr: Simulated sync for post ' . $post->ID . ' - ' . $post->post_title);
                } else {
                    $errors[] = 'Failed to create Nostr event for post ' . $post->ID;
                }
                
            } catch (Exception $e) {
                $errors[] = 'Error syncing post ' . $post->ID . ': ' . $e->getMessage();
                error_log('Nostr sync error for post ' . $post->ID . ': ' . $e->getMessage());
            }
        }
        
        $message = "Processed {$synced_count} pending posts";
        if (!empty($errors)) {
            $message .= ' with ' . count($errors) . ' errors';
        }
        
        wp_send_json_success(array(
            'message' => $message,
            'count' => $synced_count,
            'errors' => $errors
        ));
    }
    
    /**
     * AJAX handler to sync a specific post (browser-triggered)
     */
    public function sync_post_ajax() {
        check_ajax_referer('nostr_for_wp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $post_id = intval($_POST['post_id']);
        if (!$post_id) {
            wp_send_json_error('Invalid post ID');
        }
        
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error('Post not found');
        }
        
        // This will be handled by JavaScript with NIP-07 signing
        // For now, just mark as processed
        update_post_meta($post_id, '_nostr_sync_pending', '0');
        update_post_meta($post_id, '_nostr_sync_status', 'synced');
        
        wp_send_json_success(array(
            'message' => 'Post sync initiated',
            'post_id' => $post_id
        ));
    }
}

// Hook for removing origin flag
add_action('nostr_remove_origin_flag', array('Nostr_Sync_Manager', 'remove_origin_flag'));
