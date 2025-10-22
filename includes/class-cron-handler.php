<?php
/**
 * Cron Handler Class
 * 
 * Handles background polling for Nostr updates
 */

if (!defined('ABSPATH')) {
    exit;
}

class Nostr_Cron_Handler {
    
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
        // Register custom cron interval
        add_filter('cron_schedules', array($this, 'add_cron_interval'));
        
        // Register cron job
        add_action('nostr_poll_updates', array($this, 'poll_nostr_updates'));
        
        // Add activation/deactivation hooks
        register_activation_hook(NOSTR_FOR_WP_PLUGIN_FILE, array($this, 'schedule_cron'));
        register_deactivation_hook(NOSTR_FOR_WP_PLUGIN_FILE, array($this, 'unschedule_cron'));
    }
    
    /**
     * Add custom cron interval
     */
    public function add_cron_interval($schedules) {
        $options = get_option('nostr_for_wp_options', array());
        $interval = isset($options['sync_interval']) ? intval($options['sync_interval']) : 300; // Default 5 minutes
        
        $schedules['nostr_poll_interval'] = array(
            'interval' => $interval,
            'display' => sprintf(__('Every %d seconds', 'nostr-for-wp'), $interval)
        );
        
        return $schedules;
    }
    
    /**
     * Schedule cron job
     */
    public function schedule_cron() {
        if (!wp_next_scheduled('nostr_poll_updates')) {
            wp_schedule_event(time(), 'nostr_poll_interval', 'nostr_poll_updates');
        }
    }
    
    /**
     * Unschedule cron job
     */
    public function unschedule_cron() {
        $timestamp = wp_next_scheduled('nostr_poll_updates');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'nostr_poll_updates');
        }
    }
    
    /**
     * Poll Nostr for updates
     */
    public function poll_nostr_updates() {
        // Get all users who have Nostr connected
        $users = $this->get_connected_users();
        
        if (empty($users)) {
            return;
        }
        
        $sync_manager = Nostr_Sync_Manager::get_instance();
        
        foreach ($users as $user_id) {
            try {
                $sync_manager->sync_from_nostr($user_id);
            } catch (Exception $e) {
                error_log('Nostr cron sync failed for user ' . $user_id . ': ' . $e->getMessage());
            }
        }
        
        // Update last sync time
        update_option('nostr_last_cron_sync', current_time('mysql'));
    }
    
    /**
     * Get users with Nostr connected
     */
    private function get_connected_users() {
        global $wpdb;
        
        $users = $wpdb->get_col("
            SELECT user_id 
            FROM {$wpdb->usermeta} 
            WHERE meta_key = 'nostr_public_key' 
            AND meta_value != ''
        ");
        
        return array_map('intval', $users);
    }
    
    /**
     * Manual sync trigger
     */
    public function trigger_manual_sync($user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        if (!$user_id) {
            return false;
        }
        
        $sync_manager = Nostr_Sync_Manager::get_instance();
        return $sync_manager->sync_from_nostr($user_id);
    }
    
    /**
     * Get cron status
     */
    public function get_cron_status() {
        $next_run = wp_next_scheduled('nostr_poll_updates');
        $last_sync = get_option('nostr_last_cron_sync');
        
        return array(
            'scheduled' => $next_run !== false,
            'next_run' => $next_run ? date('Y-m-d H:i:s', $next_run) : null,
            'last_sync' => $last_sync,
            'interval' => wp_get_schedule('nostr_poll_updates')
        );
    }
    
    /**
     * Force immediate sync
     */
    public function force_sync() {
        $this->poll_nostr_updates();
    }
    
    /**
     * Get sync statistics
     */
    public function get_sync_stats() {
        $stats = array(
            'connected_users' => 0,
            'total_events_processed' => 0,
            'last_sync' => null,
            'cron_status' => $this->get_cron_status()
        );
        
        // Count connected users
        $connected_users = $this->get_connected_users();
        $stats['connected_users'] = count($connected_users);
        
        // Get last sync time
        $stats['last_sync'] = get_option('nostr_last_cron_sync');
        
        // Count total events processed (this would need to be tracked separately)
        $stats['total_events_processed'] = get_option('nostr_events_processed', 0);
        
        return $stats;
    }
    
    /**
     * Update sync interval
     */
    public function update_sync_interval($interval) {
        // Validate interval (minimum 60 seconds)
        $interval = max(60, intval($interval));
        
        // Update options
        $options = get_option('nostr_for_wp_options', array());
        $options['sync_interval'] = $interval;
        update_option('nostr_for_wp_options', $options);
        
        // Reschedule cron with new interval
        $this->unschedule_cron();
        $this->schedule_cron();
        
        return true;
    }
    
    /**
     * Test relay connections for all users
     */
    public function test_all_relays() {
        $users = $this->get_connected_users();
        $results = array();
        
        $nip07_handler = Nostr_NIP07_Handler::get_instance();
        
        foreach ($users as $user_id) {
            $relays = $nip07_handler->get_user_relays($user_id);
            $user_results = array();
            
            foreach ($relays as $relay) {
                $user_results[$relay] = $nip07_handler->test_relay($relay);
            }
            
            $results[$user_id] = $user_results;
        }
        
        return $results;
    }
    
    /**
     * Clean up old sync data
     */
    public function cleanup_old_data($days = 30) {
        global $wpdb;
        
        $cutoff_date = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
        
        // Clean up old sync status entries
        $wpdb->query($wpdb->prepare("
            DELETE FROM {$wpdb->postmeta} 
            WHERE meta_key = '_nostr_sync_status' 
            AND meta_value = 'failed' 
            AND post_id IN (
                SELECT ID FROM {$wpdb->posts} 
                WHERE post_modified < %s
            )
        ", $cutoff_date));
        
        return true;
    }
    
    /**
     * Get sync logs
     */
    public function get_sync_logs($limit = 50) {
        global $wpdb;
        
        $logs = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID, p.post_title, p.post_type, pm.meta_value as sync_status, pm2.meta_value as synced_at
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_nostr_sync_status'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_nostr_synced_at'
            WHERE p.post_status = 'publish'
            AND (pm.meta_value IS NOT NULL OR pm2.meta_value IS NOT NULL)
            ORDER BY pm2.meta_value DESC
            LIMIT %d
        ", $limit));
        
        return $logs;
    }
}
