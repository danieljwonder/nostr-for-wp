<?php
/**
 * Frontend Display Handler
 * 
 * Handles frontend display customization for note post types
 */

if (!defined('ABSPATH')) {
    exit;
}

class Nostr_Frontend_Display {
    
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
        // Add body class for notes
        add_filter('body_class', array($this, 'add_note_body_class'));
        
        // Add custom class to note posts
        add_filter('post_class', array($this, 'add_note_post_class'));
        
        // Enhance note content with wrapper and metadata
        add_filter('the_content', array($this, 'enhance_note_content'), 10);
        
        // Enqueue frontend styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_styles'));
        
        // Hide default entry header elements for notes (filter-based approach)
        add_action('template_redirect', array($this, 'setup_note_template_filters'));
    }
    
    /**
     * Add body class when viewing a note
     */
    public function add_note_body_class($classes) {
        if (is_singular('note') || is_post_type_archive('note')) {
            $classes[] = 'nostr-note-page';
        }
        if (is_singular('note')) {
            $classes[] = 'single-note';
        }
        if (is_post_type_archive('note')) {
            $classes[] = 'archive-note';
        }
        return $classes;
    }
    
    /**
     * Setup template filters for note display
     * Uses WordPress filters instead of CSS to control output
     */
    public function setup_note_template_filters() {
        if (!is_singular('note')) {
            return;
        }
        
        // Remove theme's default entry header/title output
        // This is theme-agnostic and works with most themes
        add_filter('the_title', array($this, 'filter_note_title'), 10, 2);
        
        // For block themes, hide post metadata blocks
        add_filter('render_block', array($this, 'filter_note_metadata_blocks'), 10, 2);
    }
    
    /**
     * Filter the title display for notes
     * Only shows title in admin, feeds, and navigation - hides in main content
     */
    public function filter_note_title($title, $post_id = null) {
        // Don't modify in admin, feeds, or when not in the main loop
        if (is_admin() || is_feed() || !in_the_loop() || !is_main_query()) {
            return $title;
        }
        
        $post = get_post($post_id);
        if ($post && $post->post_type === 'note') {
            // Return empty string - our content filter will add the proper note display
            return '';
        }
        
        return $title;
    }
    
    /**
     * Filter block output to hide metadata blocks for notes
     * Works with block themes (Twenty Twenty-Three, etc.)
     */
    public function filter_note_metadata_blocks($block_content, $block) {
        // Only on single note pages
        if (!is_singular('note')) {
            return $block_content;
        }
        
        // Hide post date and post author blocks
        if (in_array($block['blockName'], array('core/post-date', 'core/post-author'))) {
            return '';
        }
        
        return $block_content;
    }
    
    /**
     * Enhance note content with wrapper and metadata
     */
    public function enhance_note_content($content) {
        if (!is_admin() && in_the_loop()) {
            $post = get_post();
            
            // Only modify note post types
            if ($post && $post->post_type === 'note') {
                // Wrap content in a div with nostr-note-content class
                $content = '<div class="nostr-note-content">' . $content . '</div>';
                
                // Add metadata after content on single pages
                if (is_singular('note')) {
                    $meta = '<div class="nostr-note-meta">';
                    $meta .= '<time datetime="' . esc_attr(get_the_date('c')) . '">';
                    $meta .= esc_html(get_the_date('l, F j, Y \a\t g:i'));
                    $meta .= '</time>';
                    
                    $event_id = get_post_meta(get_the_ID(), '_nostr_event_id', true);
                    if ($event_id) {
                        $meta .= '<div class="nostr-event-id">';
                        $meta .= '<a href="' . esc_url(get_permalink()) . '" rel="bookmark">';
                        $meta .= esc_html($event_id);
                        $meta .= '</a>';
                        $meta .= '</div>';
                    }
                    
                    $meta .= '</div>';
                    $content .= $meta;
                }
            }
        }
        return $content;
    }
    
    /**
     * Add custom class to note posts
     */
    public function add_note_post_class($classes) {
        $post = get_post();
        if ($post && $post->post_type === 'note') {
            $classes[] = 'nostr-note';
        }
        return $classes;
    }
    
    /**
     * Enqueue frontend styles
     */
    public function enqueue_frontend_styles() {
        // Enqueue on note pages, archives, or when shortcodes/blocks might be present
        if (is_singular('note') || is_post_type_archive('note')) {
            wp_enqueue_style(
                'nostr-for-wp-frontend',
                NOSTR_FOR_WP_PLUGIN_URL . 'assets/css/nostr-frontend.css',
                array(),
                NOSTR_FOR_WP_VERSION
            );
        }
    }
}

