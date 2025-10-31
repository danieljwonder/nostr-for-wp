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
        
        // Hide title for notes on single pages
        add_filter('the_title', array($this, 'hide_note_title_single'), 10, 2);
        
        // Add inline styles to hide theme's meta row
        add_action('wp_head', array($this, 'add_meta_hiding_styles'));
        
        // Enhance note content with wrapper and metadata
        add_filter('the_content', array($this, 'enhance_note_content'), 10);
        
        // Enqueue frontend styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_styles'));
        
        // Add custom class to note posts
        add_filter('post_class', array($this, 'add_note_post_class'));
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
     * Hide title for notes on single pages
     * 
     * Note: CSS is the primary method for hiding titles, but this filter
     * helps in cases where themes check for empty titles.
     */
    public function hide_note_title_single($title, $post_id = null) {
        // Only modify on frontend single note pages
        if (!is_admin() && is_singular('note')) {
            global $post;
            
            // Use the global post if post_id is not provided
            $current_post = $post_id ? get_post($post_id) : $post;
            
            // Only hide if this is a note post type and we're in the loop
            if ($current_post && $current_post->post_type === 'note' && in_the_loop()) {
                // Return single space to avoid layout issues, CSS will hide it
                return ' ';
            }
        }
        
        return $title;
    }
    
    /**
     * Add inline styles to hide theme meta (more specific targeting)
     */
    public function add_meta_hiding_styles() {
        if (is_singular('note')) {
            echo '<style>
                body.single-note article.nostr-note .entry-meta:not(.nostr-note-meta),
                body.single-note article.nostr-note .post-meta:not(.nostr-note-meta),
                body.post-type-note.single article.nostr-note .entry-meta:not(.nostr-note-meta),
                body.post-type-note.single article.nostr-note .post-meta:not(.nostr-note-meta),
                body.single-note article.nostr-note > header .entry-meta,
                body.single-note article.nostr-note > header .post-meta,
                body.post-type-note.single article.nostr-note > header .entry-meta,
                body.post-type-note.single article.nostr-note > header .post-meta,
                body.single-note .wp-block-post-date,
                body.post-type-note.single .wp-block-post-date,
                body.single-note .wp-block-group:has(.wp-block-post-date),
                body.post-type-note.single .wp-block-group:has(.wp-block-post-date) {
                    display: none !important;
                }
            </style>';
        }
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

