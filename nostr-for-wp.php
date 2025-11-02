<?php
/**
 * Plugin Name: Nostr for WordPress
 * Plugin URI: https://github.com/danieljwonder/nostr-for-wp
 * Description: Two-way synchronization between WordPress content and Nostr protocol. Supports kind 1 notes and kind 30023 long-form content with NIP-07 browser extension signing.
 * Version: 1.2.0
 * Author: Daniel Wonder
 * License: GPL v2 or later
 * Text Domain: nostr-for-wp
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('NOSTR_FOR_WP_VERSION', '1.2.0');
define('NOSTR_FOR_WP_PLUGIN_FILE', __FILE__);
define('NOSTR_FOR_WP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('NOSTR_FOR_WP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('NOSTR_FOR_WP_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Toggle verbose debug logging (set to true to enable detailed logging, false to disable)
if (!defined('NOSTR_FOR_WP_VERBOSE_DEBUG')) {
    define('NOSTR_FOR_WP_VERBOSE_DEBUG', false);
}

/**
 * Helper function to log debug messages (only if verbose debugging is enabled)
 * 
 * @param string $message The message to log
 */
function nostr_for_wp_debug_log($message) {
    if (!NOSTR_FOR_WP_VERBOSE_DEBUG) {
        return;
    }
    
    $log_file = WP_CONTENT_DIR . '/nostr-debug.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "{$timestamp} - {$message}\n", FILE_APPEND | LOCK_EX);
}

/**
 * Main plugin class
 */
class Nostr_For_WP {
    
    /**
     * Single instance of the plugin
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
        $this->load_dependencies();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        add_action('init', array($this, 'init'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
    }
    
    /**
     * Load plugin dependencies
     */
    private function load_dependencies() {
        // Load core classes
        require_once NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-websocket-client.php';
        require_once NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-nostr-client.php';
        require_once NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-sync-manager.php';
        require_once NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-content-mapper.php';
        require_once NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-nip07-handler.php';
        require_once NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-cron-handler.php';
        require_once NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-frontend-display.php';
        require_once NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-note-shortcode.php';
        
        // Load admin classes
        if (is_admin()) {
            require_once NOSTR_FOR_WP_PLUGIN_DIR . 'admin/settings-page.php';
            require_once NOSTR_FOR_WP_PLUGIN_DIR . 'admin/meta-boxes.php';
        }
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Load text domain
        load_plugin_textdomain('nostr-for-wp', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // Register custom post type
        $this->register_post_types();
        
        // Initialize core classes
        Nostr_Client::get_instance();
        Nostr_Sync_Manager::get_instance();
        Nostr_Content_Mapper::get_instance();
        Nostr_Cron_Handler::get_instance();
        Nostr_Frontend_Display::get_instance();
        Nostr_Note_Shortcode::get_instance();
        
        // Register blocks
        add_action('init', array($this, 'register_blocks'), 20);
        
        if (is_admin()) {
            Nostr_Admin_Settings::get_instance();
            Nostr_Admin_Meta_Boxes::get_instance();
        }
    }
    
    /**
     * Admin initialization
     */
    public function admin_init() {
        // Register settings
        register_setting('nostr_for_wp_settings', 'nostr_for_wp_options');
    }
    
    /**
     * Add admin menu
     */
    public function admin_menu() {
        add_options_page(
            __('Nostr Settings', 'nostr-for-wp'),
            __('Nostr', 'nostr-for-wp'),
            'manage_options',
            'nostr-for-wp',
            array('Nostr_Admin_Settings', 'render_settings_page')
        );
    }
    
    /**
     * Enqueue admin scripts
     */
    public function admin_enqueue_scripts($hook) {
        // Load settings page JavaScript
        if (strpos($hook, 'nostr-for-wp') !== false || strpos($hook, 'options-general') !== false) {
            wp_enqueue_script(
                'nostr-for-wp-admin',
                NOSTR_FOR_WP_PLUGIN_URL . 'admin/assets/js/nostr-admin.js',
                array('jquery'),
                NOSTR_FOR_WP_VERSION,
                true
            );
            
            // Localize script for settings page
            wp_localize_script('nostr-for-wp-admin', 'nostrForWPAdmin', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('nostr_for_wp_admin_nonce'),
                'relays' => get_user_meta(get_current_user_id(), 'nostr_relays', true) ?: array(),
                'strings' => array(
                    'nip07NotAvailable' => __('NIP-07 browser extension not detected', 'nostr-for-wp'),
                    'syncSuccess' => __('Sync completed successfully', 'nostr-for-wp'),
                    'syncError' => __('Sync failed', 'nostr-for-wp'),
                    'connecting' => __('Connecting...', 'nostr-for-wp'),
                    'test' => __('Test', 'nostr-for-wp'),
                    'remove' => __('Remove', 'nostr-for-wp'),
                    'addRelay' => __('Add Relay', 'nostr-for-wp'),
                    'saveRelays' => __('Save Relays', 'nostr-for-wp'),
                    'testing' => __('Testing...', 'nostr-for-wp'),
                    'saving' => __('Saving...', 'nostr-for-wp'),
                    'relaysSaved' => __('Relays saved successfully', 'nostr-for-wp'),
                    'relaysSaveFailed' => __('Failed to save relays', 'nostr-for-wp'),
                    'relayUrlRequired' => __('Please enter a relay URL', 'nostr-for-wp'),
                    'extensionAvailable' => __('NIP-07 extension detected', 'nostr-for-wp'),
                    'extensionNotAvailable' => __('NIP-07 extension not detected', 'nostr-for-wp'),
                    'connectionSuccess' => __('Connected successfully', 'nostr-for-wp'),
                    'connectionFailed' => __('Connection failed', 'nostr-for-wp'),
                    'disconnectConfirm' => __('Are you sure you want to disconnect?', 'nostr-for-wp'),
                    'disconnectFailed' => __('Failed to disconnect', 'nostr-for-wp'),
                    'connect' => __('Connect with NIP-07', 'nostr-for-wp'),
                    'disconnect' => __('Disconnect', 'nostr-for-wp'),
                    'forceSync' => __('Force Sync Now', 'nostr-for-wp'),
                    'testAllRelays' => __('Test All Relays', 'nostr-for-wp'),
                    'syncing' => __('Syncing...', 'nostr-for-wp'),
                    'syncNow' => __('Sync Now', 'nostr-for-wp'),
                    'refreshStatus' => __('Refresh Status', 'nostr-for-wp'),
                    'refreshing' => __('Refreshing...', 'nostr-for-wp'),
                    'synced' => __('Synced', 'nostr-for-wp'),
                    'failed' => __('Failed', 'nostr-for-wp'),
                    'error' => __('Error', 'nostr-for-wp'),
                    'pending' => __('Pending', 'nostr-for-wp'),
                    'statusRefreshed' => __('Status refreshed', 'nostr-for-wp'),
                    'statusRefreshFailed' => __('Failed to refresh status', 'nostr-for-wp'),
                    'statusRefreshRequestFailed' => __('Status refresh request failed', 'nostr-for-wp'),
                    'invalidPostId' => __('Invalid post ID', 'nostr-for-wp'),
                    'syncRequestFailed' => __('Sync request failed', 'nostr-for-wp'),
                    'unknownError' => __('Unknown error', 'nostr-for-wp'),
                    'syncSuccess' => __('Sync completed successfully', 'nostr-for-wp'),
                    'syncError' => __('Sync failed', 'nostr-for-wp')
                )
            ));
        }
        
        // Load post edit page JavaScript
        if (in_array($hook, array('post.php', 'post-new.php'))) {
            wp_enqueue_script(
                'nostr-for-wp-post-sync',
                NOSTR_FOR_WP_PLUGIN_URL . 'admin/assets/js/nostr-post-sync.js',
                array('jquery'),
                NOSTR_FOR_WP_VERSION,
                true
            );
            
            // Localize script for post pages
            wp_localize_script('nostr-for-wp-post-sync', 'nostrForWPAdmin', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('nostr_for_wp_admin_nonce'),
                'relays' => get_user_meta(get_current_user_id(), 'nostr_relays', true) ?: array(),
                'strings' => array(
                    'syncing' => __('Syncing...', 'nostr-for-wp'),
                    'syncNow' => __('Sync Now', 'nostr-for-wp'),
                    'refreshing' => __('Refreshing...', 'nostr-for-wp'),
                    'refreshStatus' => __('Refresh Status', 'nostr-for-wp'),
                    'synced' => __('Synced', 'nostr-for-wp'),
                    'failed' => __('Failed', 'nostr-for-wp'),
                    'pending' => __('Pending', 'nostr-for-wp'),
                    'statusRefreshed' => __('Status refreshed', 'nostr-for-wp'),
                    'statusRefreshFailed' => __('Failed to refresh status', 'nostr-for-wp'),
                    'statusRefreshRequestFailed' => __('Status refresh request failed', 'nostr-for-wp')
                )
            ));
        }
        
        // Load admin CSS for both settings and post pages
        if (strpos($hook, 'nostr-for-wp') !== false || strpos($hook, 'options-general') !== false || in_array($hook, array('post.php', 'post-new.php'))) {
            wp_enqueue_style(
                'nostr-for-wp-admin',
                NOSTR_FOR_WP_PLUGIN_URL . 'admin/assets/css/nostr-admin.css',
                array(),
                NOSTR_FOR_WP_VERSION
            );
        }
    }
    
    /**
     * Register Gutenberg blocks
     */
    public function register_blocks() {
        // Only register if block editor is available
        if (!function_exists('register_block_type')) {
            return;
        }
        
        // Register Nostr Notes block (from build directory)
        register_block_type(NOSTR_FOR_WP_PLUGIN_DIR . 'build/blocks/nostr-notes', array(
            'render_callback' => array($this, 'render_nostr_notes_block'),
        ));
        
        // Register single Nostr Note block (from build directory)
        register_block_type(NOSTR_FOR_WP_PLUGIN_DIR . 'build/blocks/nostr-note', array(
            'render_callback' => array($this, 'render_nostr_note_block'),
        ));
    }
    
    /**
     * Render Nostr Notes block
     */
    public function render_nostr_notes_block($attributes) {
        $attributes = wp_parse_args($attributes, array(
            'limit' => 10,
            'orderby' => 'date',
            'order' => 'DESC',
        ));
        
        return do_shortcode(sprintf(
            '[nostr_notes limit="%d" orderby="%s" order="%s"]',
            intval($attributes['limit']),
            esc_attr($attributes['orderby']),
            esc_attr($attributes['order'])
        ));
    }
    
    /**
     * Render single Nostr Note block
     */
    public function render_nostr_note_block($attributes) {
        $attributes = wp_parse_args($attributes, array(
            'noteId' => 0,
        ));
        
        if (!$attributes['noteId']) {
            return '<p>' . esc_html__('Please select a note.', 'nostr-for-wp') . '</p>';
        }
        
        return do_shortcode(sprintf('[nostr_note id="%d"]', intval($attributes['noteId'])));
    }
    
    /**
     * Register custom post types
     */
    private function register_post_types() {
        // Register Notes post type for kind 1 events
        register_post_type('note', array(
            'labels' => array(
                'name' => __('Notes', 'nostr-for-wp'),
                'singular_name' => __('Note', 'nostr-for-wp'),
                'menu_name' => __('Notes', 'nostr-for-wp'),
                'add_new' => __('Add New Note', 'nostr-for-wp'),
                'add_new_item' => __('Add New Note', 'nostr-for-wp'),
                'edit_item' => __('Edit Note', 'nostr-for-wp'),
                'new_item' => __('New Note', 'nostr-for-wp'),
                'view_item' => __('View Note', 'nostr-for-wp'),
                'search_items' => __('Search Notes', 'nostr-for-wp'),
                'not_found' => __('No notes found', 'nostr-for-wp'),
                'not_found_in_trash' => __('No notes found in trash', 'nostr-for-wp')
            ),
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => true,
            'query_var' => true,
            'rewrite' => array('slug' => 'note'),
            'capability_type' => 'post',
            'has_archive' => true,
            'hierarchical' => false,
            'menu_position' => 20,
            'menu_icon' => 'dashicons-format-chat',
            'supports' => array('title', 'editor', 'author', 'custom-fields', 'revisions'),
            'show_in_nav_menus' => true
        ));
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Load cron handler directly since dependencies might not be loaded yet
        require_once NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-cron-handler.php';
        
        // Flush rewrite rules
        $this->register_post_types();
        flush_rewrite_rules();
        
        // Set default options
        $default_options = array(
            'default_relays' => array(
                'wss://relay.damus.io',
                'wss://relay.snort.social',
                'wss://nos.lol'
            ),
            'sync_interval' => 300, // 5 minutes
            'auto_sync_enabled' => true
        );
        
        add_option('nostr_for_wp_options', $default_options);
        
        // Schedule cron job using the cron handler (which will check auto_sync_enabled)
        $cron_handler = Nostr_Cron_Handler::get_instance();
        $cron_handler->schedule_cron();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Load cron handler directly since dependencies might not be loaded yet
        require_once NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-cron-handler.php';
        
        // Unschedule cron job using the cron handler
        $cron_handler = Nostr_Cron_Handler::get_instance();
        $cron_handler->unschedule_cron();
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
}

/**
 * Initialize the plugin
 */
function nostr_for_wp() {
    return Nostr_For_WP::get_instance();
}

// Start the plugin
nostr_for_wp();
