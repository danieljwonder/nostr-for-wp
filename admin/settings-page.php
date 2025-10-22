<?php
/**
 * Admin Settings Page
 * 
 * Handles the Nostr settings page in WordPress admin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Nostr_Admin_Settings {
    
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
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_nostr_save_public_key', array($this, 'save_public_key_ajax'));
        add_action('wp_ajax_nostr_disconnect', array($this, 'disconnect_ajax'));
        add_action('wp_ajax_nostr_test_relay', array($this, 'test_relay_ajax'));
        add_action('wp_ajax_nostr_save_relays', array($this, 'save_relays_ajax'));
        add_action('wp_ajax_nostr_force_sync', array($this, 'force_sync_ajax'));
        add_action('wp_ajax_nostr_get_pending_posts', array($this, 'get_pending_posts_ajax'));
        add_action('wp_ajax_nostr_get_post_event', array($this, 'get_post_event_ajax'));
        add_action('wp_ajax_nostr_publish_event', array($this, 'publish_event_ajax'));
        add_action('wp_ajax_nostr_mark_post_synced', array($this, 'mark_post_synced_ajax'));
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('nostr_for_wp_settings', 'nostr_for_wp_options', array(
            'sanitize_callback' => array($this, 'sanitize_options')
        ));
    }
    
    /**
     * Sanitize options
     */
    public function sanitize_options($options) {
        if (isset($options['default_relays'])) {
            $options['default_relays'] = array_filter($options['default_relays'], function($relay) {
                return filter_var($relay, FILTER_VALIDATE_URL) && 
                       (strpos($relay, 'wss://') === 0 || strpos($relay, 'ws://') === 0);
            });
        }
        
        if (isset($options['sync_interval'])) {
            $options['sync_interval'] = max(60, intval($options['sync_interval']));
        }
        
        return $options;
    }
    
    /**
     * Render settings page
     */
    public static function render_settings_page() {
        $nip07_handler = Nostr_NIP07_Handler::get_instance();
        $cron_handler = Nostr_Cron_Handler::get_instance();
        
        $connection_status = $nip07_handler->get_connection_status();
        $cron_status = $cron_handler->get_cron_status();
        $sync_stats = $cron_handler->get_sync_stats();
        
        ?>
        <div class="wrap">
            <h1><?php _e('Nostr Settings', 'nostr-for-wp'); ?></h1>
            
            <div id="nostr-admin-messages"></div>
            
            <div class="nostr-admin-container">
                <!-- Connection Status -->
                <div class="nostr-card">
                    <h2><?php _e('Nostr Connection', 'nostr-for-wp'); ?></h2>
                    
                    <div id="nostr-connection-status">
                        <?php if ($connection_status['connected']): ?>
                            <div class="nostr-status connected">
                                <span class="dashicons dashicons-yes-alt"></span>
                                <strong><?php _e('Connected', 'nostr-for-wp'); ?></strong>
                                <p><?php printf(__('Your Public Key: %s', 'nostr-for-wp'), substr($connection_status['public_key'], 0, 16) . '...'); ?></p>
                                <p class="description"><?php _e('This is your personal Nostr identity. Each user has their own key.', 'nostr-for-wp'); ?></p>
                                <button type="button" class="button" id="nostr-disconnect"><?php _e('Disconnect', 'nostr-for-wp'); ?></button>
                            </div>
                        <?php else: ?>
                            <div class="nostr-status disconnected">
                                <span class="dashicons dashicons-warning"></span>
                                <strong><?php _e('Not Connected', 'nostr-for-wp'); ?></strong>
                                <p><?php _e('Connect your Nostr account to enable synchronization.', 'nostr-for-wp'); ?></p>
                                <p class="description"><?php _e('You need a NIP-07 compatible browser extension (like Alby or nos2x) to connect.', 'nostr-for-wp'); ?></p>
                                <button type="button" class="button button-primary" id="nostr-connect"><?php _e('Connect with NIP-07', 'nostr-for-wp'); ?></button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Relay Configuration -->
                <div class="nostr-card">
                    <h2><?php _e('Relay Configuration', 'nostr-for-wp'); ?></h2>
                    <p class="description"><?php _e('Configure your Nostr relays. The test button checks basic connectivity to the relay host/port.', 'nostr-for-wp'); ?></p>
                    
                    <form id="nostr-relays-form">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Relays', 'nostr-for-wp'); ?></th>
                                <td>
                                    <div id="nostr-relays-list">
                                        <?php foreach ($connection_status['relays'] as $index => $relay): ?>
                                            <div class="nostr-relay-item">
                                                <input type="url" name="relays[]" value="<?php echo esc_attr($relay); ?>" class="regular-text" placeholder="wss://relay.example.com">
                                                <button type="button" class="button nostr-test-relay" data-relay="<?php echo esc_attr($relay); ?>"><?php _e('Test', 'nostr-for-wp'); ?></button>
                                                <button type="button" class="button nostr-remove-relay"><?php _e('Remove', 'nostr-for-wp'); ?></button>
                                                <span class="nostr-relay-status" data-relay="<?php echo esc_attr($relay); ?>">
                                                    <?php if (isset($connection_status['relay_status'][$relay]) && $connection_status['relay_status'][$relay]): ?>
                                                        <span class="dashicons dashicons-yes-alt" style="color: green;" title="Relay is working"></span>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="button" class="button" id="nostr-add-relay"><?php _e('Add Relay', 'nostr-for-wp'); ?></button>
                                    <button type="button" class="button button-primary" id="nostr-save-relays"><?php _e('Save Relays', 'nostr-for-wp'); ?></button>
                                    <p class="description" style="margin-top: 10px; font-style: italic;">
                                        <?php _e('Note: The test checks basic TCP connectivity to the relay host/port. Real WebSocket connections will be tested during actual sync operations.', 'nostr-for-wp'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </form>
                </div>
                
                <!-- Sync Settings -->
                <div class="nostr-card">
                    <h2><?php _e('Sync Settings', 'nostr-for-wp'); ?></h2>
                    
                    <form method="post" action="options.php">
                        <?php settings_fields('nostr_for_wp_settings'); ?>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Sync Interval', 'nostr-for-wp'); ?></th>
                                <td>
                                    <input type="number" name="nostr_for_wp_options[sync_interval]" value="<?php echo esc_attr(get_option('nostr_for_wp_options')['sync_interval'] ?? 300); ?>" min="60" max="3600" step="60">
                                    <p class="description"><?php _e('How often to check for updates from Nostr (in seconds). Minimum: 60 seconds.', 'nostr-for-wp'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Auto Sync', 'nostr-for-wp'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="nostr_for_wp_options[auto_sync_enabled]" value="1" <?php checked(get_option('nostr_for_wp_options')['auto_sync_enabled'] ?? true); ?>>
                                        <?php _e('Enable automatic synchronization', 'nostr-for-wp'); ?>
                                    </label>
                                </td>
                            </tr>
                        </table>
                        
                        <?php submit_button(); ?>
                    </form>
                </div>
                
                <!-- Sync Status -->
                <div class="nostr-card">
                    <h2><?php _e('Sync Status', 'nostr-for-wp'); ?></h2>
                    
                    <div class="nostr-sync-stats">
                        <p><strong><?php _e('Connected Users:', 'nostr-for-wp'); ?></strong> <?php echo $sync_stats['connected_users']; ?></p>
                        <p><strong><?php _e('Last Sync:', 'nostr-for-wp'); ?></strong> <?php echo $sync_stats['last_sync'] ? date('Y-m-d H:i:s', strtotime($sync_stats['last_sync'])) : __('Never', 'nostr-for-wp'); ?></p>
                        <p><strong><?php _e('Next Sync:', 'nostr-for-wp'); ?></strong> <?php echo $cron_status['next_run'] ? date('Y-m-d H:i:s', strtotime($cron_status['next_run'])) : __('Not scheduled', 'nostr-for-wp'); ?></p>
                        <p><strong><?php _e('Cron Status:', 'nostr-for-wp'); ?></strong> 
                            <?php if ($cron_status['scheduled']): ?>
                                <span style="color: green;"><?php _e('Active', 'nostr-for-wp'); ?></span>
                            <?php else: ?>
                                <span style="color: red;"><?php _e('Inactive', 'nostr-for-wp'); ?></span>
                            <?php endif; ?>
                        </p>
                    </div>
                    
                    <p>
                        <button type="button" class="button" id="nostr-force-sync"><?php _e('Force Sync Now', 'nostr-for-wp'); ?></button>
                        <button type="button" class="button" id="nostr-test-all-relays"><?php _e('Test All Relays', 'nostr-for-wp'); ?></button>
                    </p>
                    
                </div>
            </div>
        </div>
        
        <style>
        .nostr-admin-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 20px;
        }
        
        .nostr-card {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        
        .nostr-card h2 {
            margin-top: 0;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        
        .nostr-status {
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        
        .nostr-status.connected {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        
        .nostr-status.disconnected {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        
        .nostr-relay-item {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
            gap: 10px;
        }
        
        .nostr-relay-item input {
            flex: 1;
        }
        
        .nostr-sync-stats p {
            margin: 10px 0;
        }
        </style>
        
        <?php
    }
    
    /**
     * Save public key via AJAX
     */
    public function save_public_key_ajax() {
        check_ajax_referer('nostr_for_wp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $public_key = sanitize_text_field($_POST['public_key']);
        
        $nip07_handler = Nostr_NIP07_Handler::get_instance();
        $success = $nip07_handler->save_public_key($public_key);
        
        if ($success) {
            wp_send_json_success('Public key saved');
        } else {
            wp_send_json_error('Invalid public key');
        }
    }
    
    /**
     * Disconnect via AJAX
     */
    public function disconnect_ajax() {
        check_ajax_referer('nostr_for_wp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $nip07_handler = Nostr_NIP07_Handler::get_instance();
        $success = $nip07_handler->disconnect_user();
        
        if ($success) {
            wp_send_json_success('Disconnected');
        } else {
            wp_send_json_error('Failed to disconnect');
        }
    }
    
    /**
     * Test relay via AJAX
     */
    public function test_relay_ajax() {
        check_ajax_referer('nostr_for_wp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $relay = sanitize_text_field($_POST['relay']);
        
        // Validate relay URL
        if (empty($relay) || (!filter_var($relay, FILTER_VALIDATE_URL) && !preg_match('/^wss?:\/\//', $relay))) {
            wp_send_json_error(array('message' => 'Invalid relay URL'));
        }
        
        // Log the test attempt
        error_log('Nostr: Testing relay: ' . $relay);
        
        $nip07_handler = Nostr_NIP07_Handler::get_instance();
        $success = $nip07_handler->test_relay($relay);
        
        error_log('Nostr: Relay test result for ' . $relay . ': ' . ($success ? 'success' : 'failed'));
        
        wp_send_json_success(array('success' => $success));
    }
    
    /**
     * Save relays via AJAX
     */
    public function save_relays_ajax() {
        check_ajax_referer('nostr_for_wp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        // Get relays from POST data
        $relays = isset($_POST['relays']) ? $_POST['relays'] : array();
        
        // Validate and sanitize relay URLs
        $valid_relays = array();
        foreach ($relays as $relay) {
            $relay = trim($relay);
            if (!empty($relay)) {
                // Basic URL validation for WebSocket URLs
                if (filter_var($relay, FILTER_VALIDATE_URL) && 
                    (strpos($relay, 'wss://') === 0 || strpos($relay, 'ws://') === 0)) {
                    // Don't use esc_url_raw for WebSocket URLs as it strips them
                    $valid_relays[] = sanitize_text_field($relay);
                }
            }
        }
        
        $nip07_handler = Nostr_NIP07_Handler::get_instance();
        $success = $nip07_handler->save_user_relays($valid_relays);
        
        if ($success) {
            wp_send_json_success(array(
                'message' => 'Relays saved successfully',
                'relays' => $valid_relays
            ));
        } else {
            wp_send_json_error('Failed to save relays');
        }
    }
    
    /**
     * Force sync via AJAX (inbound only - Nostr → WordPress)
     */
    public function force_sync_ajax() {
        check_ajax_referer('nostr_for_wp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        // Only handle inbound sync (Nostr → WordPress)
        // Outbound sync (WordPress → Nostr) is handled by browser with NIP-07 signing
        $cron_handler = Nostr_Cron_Handler::get_instance();
        $success = $cron_handler->poll_nostr_updates();
        
        error_log('Nostr: Background inbound sync completed');
        
        if ($success) {
            wp_send_json_success('Inbound sync completed');
        } else {
            wp_send_json_error('Inbound sync failed');
        }
    }
    
    /**
     * Get pending posts for NIP-07 signing
     */
    public function get_pending_posts_ajax() {
        error_log('Nostr: get_pending_posts_ajax called');
        check_ajax_referer('nostr_for_wp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
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
        
        error_log('Nostr: Found ' . count($pending_posts) . ' pending posts');
        wp_send_json_success(array('posts' => $pending_posts));
    }
    
    /**
     * Get Nostr event data for a post
     */
    public function get_post_event_ajax() {
        error_log('Nostr: get_post_event_ajax called');
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
        
        $content_mapper = Nostr_Content_Mapper::get_instance();
        $nostr_client = Nostr_Client::get_instance();
        
        // Create the Nostr event (unsigned)
        if ($post->post_type === 'note') {
            $event = $content_mapper->note_to_nostr_event($post_id);
        } else {
            $event = $content_mapper->post_to_nostr_event($post_id);
        }
        
        if (!$event) {
            wp_send_json_error('Failed to create Nostr event');
        }
        
        // Add public key and timestamp
        $public_key = $nostr_client->get_public_key();
        if (!$public_key) {
            wp_send_json_error('No public key found');
        }
        
        $event['pubkey'] = $public_key;
        $event['created_at'] = time();
        
        // Debug timestamp
        error_log('Nostr: Current timestamp: ' . time() . ' (' . date('Y-m-d H:i:s', time()) . ')');
        
        // Ensure all required fields for NIP-07 signing
        if (!isset($event['kind'])) {
            $event['kind'] = ($post->post_type === 'note') ? 1 : 30023;
        }
        if (!isset($event['content'])) {
            $event['content'] = $post->post_content;
        }
        if (!isset($event['tags'])) {
            $event['tags'] = array();
        }
        
        // Remove any fields that shouldn't be in the unsigned event
        unset($event['id']);
        unset($event['sig']);
        
        error_log('Nostr: Event data for signing: ' . json_encode($event));
        
        wp_send_json_success(array('event' => $event));
    }
    
    /**
     * Publish signed event to relays
     */
    public function publish_event_ajax() {
        error_log('Nostr: publish_event_ajax called');
        check_ajax_referer('nostr_for_wp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $event_json = sanitize_text_field($_POST['event']);
        $event = json_decode($event_json, true);
        
        error_log('Nostr: Received signed event: ' . json_encode($event));
        
        if (!$event || !isset($event['sig'])) {
            wp_send_json_error('Invalid signed event - missing signature');
        }
        
        // The event should have: kind, content, tags, created_at, pubkey, sig
        $required_fields = ['kind', 'content', 'tags', 'created_at', 'pubkey', 'sig'];
        foreach ($required_fields as $field) {
            if (!isset($event[$field])) {
                wp_send_json_error('Invalid signed event - missing field: ' . $field);
            }
        }
        
        $nostr_client = Nostr_Client::get_instance();
        $results = $nostr_client->publish_event($event);
        
        error_log('Nostr: Publish results: ' . json_encode($results));
        
        $success = false;
        foreach ($results as $result) {
            if (isset($result['success']) && $result['success']) {
                $success = true;
                break;
            }
        }
        
        if ($success) {
            wp_send_json_success(array('success' => true, 'results' => $results));
        } else {
            wp_send_json_error('No relays accepted the event');
        }
    }
    
    /**
     * Mark post as synced
     */
    public function mark_post_synced_ajax() {
        check_ajax_referer('nostr_for_wp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $post_id = intval($_POST['post_id']);
        $event_id = isset($_POST['event_id']) ? sanitize_text_field($_POST['event_id']) : '';
        
        if (!$post_id) {
            wp_send_json_error('Invalid post ID');
        }
        
        update_post_meta($post_id, '_nostr_sync_pending', '0');
        update_post_meta($post_id, '_nostr_sync_status', 'synced');
        update_post_meta($post_id, '_nostr_synced_at', current_time('mysql'));
        update_post_meta($post_id, '_nostr_event_id', $event_id);
        
        wp_send_json_success('Post marked as synced');
    }
}
