<?php
/**
 * NIP-07 Handler Class
 * 
 * Handles integration with NIP-07 browser extension for signing events
 */

if (!defined('ABSPATH')) {
    exit;
}

class Nostr_NIP07_Handler {
    
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
        add_action('wp_ajax_nostr_get_public_key', array($this, 'get_public_key_ajax'));
        add_action('wp_ajax_nostr_sign_event', array($this, 'sign_event_ajax'));
        add_action('wp_ajax_nostr_check_extension', array($this, 'check_extension_ajax'));
    }
    
    /**
     * Get public key via AJAX
     */
    public function get_public_key_ajax() {
        check_ajax_referer('nostr_for_wp_admin_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_die('Insufficient permissions');
        }
        
        // This will be handled by JavaScript calling window.nostr.getPublicKey()
        // We just return a success response to indicate the endpoint is available
        wp_send_json_success(array(
            'message' => 'Use JavaScript to call window.nostr.getPublicKey()'
        ));
    }
    
    /**
     * Sign event via AJAX
     */
    public function sign_event_ajax() {
        check_ajax_referer('nostr_for_wp_admin_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_die('Insufficient permissions');
        }
        
        $event = isset($_POST['event']) ? json_decode(stripslashes($_POST['event']), true) : null;
        
        if (!$event) {
            wp_send_json_error('Invalid event data');
        }
        
        // This will be handled by JavaScript calling window.nostr.signEvent()
        // We just return a success response to indicate the endpoint is available
        wp_send_json_success(array(
            'message' => 'Use JavaScript to call window.nostr.signEvent()',
            'event' => $event
        ));
    }
    
    /**
     * Check if NIP-07 extension is available
     */
    public function check_extension_ajax() {
        check_ajax_referer('nostr_for_wp_admin_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_die('Insufficient permissions');
        }
        
        // This will be handled by JavaScript checking window.nostr
        // We just return a success response to indicate the endpoint is available
        wp_send_json_success(array(
            'message' => 'Use JavaScript to check window.nostr'
        ));
    }
    
    /**
     * Save site's public key (single site identity)
     */
    public function save_public_key($public_key, $user_id = null) {
        // Validate public key format (should be 64 character hex string)
        if (!preg_match('/^[a-f0-9]{64}$/i', $public_key)) {
            return false;
        }
        
        // Save to site options (single site identity)
        $options = get_option('nostr_for_wp_options', array());
        $options['public_key'] = $public_key;
        update_option('nostr_for_wp_options', $options);
        
        return true;
    }
    
    /**
     * Get site's public key (single site identity)
     */
    public function get_public_key($user_id = null) {
        $options = get_option('nostr_for_wp_options', array());
        return isset($options['public_key']) ? $options['public_key'] : null;
    }
    
    /**
     * Check if site has connected Nostr key (single site identity)
     */
    public function is_user_connected($user_id = null) {
        $public_key = $this->get_public_key();
        return !empty($public_key);
    }
    
    /**
     * Disconnect site's Nostr key (single site identity)
     */
    public function disconnect_user($user_id = null) {
        // Remove from site options
        $options = get_option('nostr_for_wp_options', array());
        unset($options['public_key']);
        update_option('nostr_for_wp_options', $options);
        
        return true;
    }
    
    /**
     * Get site's relay configuration (single site identity)
     */
    public function get_user_relays($user_id = null) {
        $options = get_option('nostr_for_wp_options', array());
        
        // Check for site-level relays
        if (isset($options['relays']) && is_array($options['relays']) && !empty($options['relays'])) {
            return $options['relays'];
        }
        
        // Fall back to default relays
        if (isset($options['default_relays']) && is_array($options['default_relays'])) {
            return $options['default_relays'];
        }
        
        // Return hardcoded defaults
        return array(
            'wss://relay.damus.io',
            'wss://relay.snort.social',
            'wss://nos.lol'
        );
    }
    
    /**
     * Save site's relay configuration (single site identity)
     */
    public function save_user_relays($relays, $user_id = null) {
        // Validate relay URLs
        $valid_relays = array();
        foreach ($relays as $relay) {
            if (filter_var($relay, FILTER_VALIDATE_URL) && 
                (strpos($relay, 'wss://') === 0 || strpos($relay, 'ws://') === 0)) {
                $valid_relays[] = $relay;
            }
        }
        
        // Save to site options
        $options = get_option('nostr_for_wp_options', array());
        $options['relays'] = $valid_relays;
        update_option('nostr_for_wp_options', $options);
        
        return true;
    }
    
    /**
     * Test relay connection
     */
    public function test_relay($relay_url) {
        $nostr_client = Nostr_Client::get_instance();
        return $nostr_client->test_relay_connection($relay_url);
    }
    
    /**
     * Get connection status for site (single site identity)
     */
    public function get_connection_status($user_id = null, $test_relays = false) {
        $status = array(
            'connected' => false,
            'public_key' => null,
            'relays' => array(),
            'relay_status' => array()
        );
        
        $public_key = $this->get_public_key();
        if ($public_key) {
            $status['connected'] = true;
            $status['public_key'] = $public_key;
        }
        
        $relays = $this->get_user_relays();
        $status['relays'] = $relays;
        
        // Only test relay connections if explicitly requested
        if ($test_relays) {
            foreach ($relays as $relay) {
                $status['relay_status'][$relay] = $this->test_relay($relay);
            }
        }
        
        return $status;
    }
    
    /**
     * Generate NIP-07 compatible event for signing
     */
    public function prepare_event_for_signing($event) {
        // Ensure event has required fields
        $prepared_event = array(
            'kind' => intval($event['kind']),
            'content' => $event['content'],
            'tags' => $event['tags'],
            'created_at' => intval($event['created_at'])
        );
        
        return $prepared_event;
    }
    
    /**
     * Validate signed event
     */
    public function validate_signed_event($signed_event) {
        $required_fields = array('id', 'pubkey', 'created_at', 'kind', 'content', 'sig');
        
        foreach ($required_fields as $field) {
            if (!isset($signed_event[$field])) {
                return false;
            }
        }
        
        // Basic validation - in production you'd want proper cryptographic validation
        return true;
    }
    
    /**
     * Get JavaScript code for NIP-07 integration
     */
    public function get_nip07_js() {
        return "
        // NIP-07 Integration JavaScript
        window.nostrForWP = {
            // Check if NIP-07 extension is available
            isExtensionAvailable: function() {
                return typeof window.nostr !== 'undefined';
            },
            
            // Get public key from extension
            getPublicKey: function() {
                if (!this.isExtensionAvailable()) {
                    throw new Error('NIP-07 extension not available');
                }
                return window.nostr.getPublicKey();
            },
            
            // Sign event with extension
            signEvent: function(event) {
                if (!this.isExtensionAvailable()) {
                    throw new Error('NIP-07 extension not available');
                }
                return window.nostr.signEvent(event);
            },
            
            // Connect user (get public key and save it)
            connect: function() {
                return this.getPublicKey().then(function(publicKey) {
                    return jQuery.post(ajaxurl, {
                        action: 'nostr_save_public_key',
                        public_key: publicKey,
                        nonce: nostrForWPAdmin.nonce
                    });
                });
            },
            
            // Disconnect user
            disconnect: function() {
                return jQuery.post(ajaxurl, {
                    action: 'nostr_disconnect',
                    nonce: nostrForWPAdmin.nonce
                });
            }
        };
        ";
    }
}
