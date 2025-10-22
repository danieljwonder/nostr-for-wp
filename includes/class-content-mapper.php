<?php
/**
 * Content Mapper Class
 * 
 * Handles transformation between WordPress content and Nostr events
 */

if (!defined('ABSPATH')) {
    exit;
}

class Nostr_Content_Mapper {
    
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
        // Initialize any required hooks
    }
    
    /**
     * Convert WordPress post to Nostr event (kind 30023 for long-form)
     */
    public function post_to_nostr_event($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return false;
        }
        
        // Check if sync is enabled for this post
        $sync_enabled = get_post_meta($post_id, '_nostr_sync_enabled', true);
        if (!$sync_enabled) {
            return false;
        }
        
        $content = $this->html_to_markdown($post->post_content);
        
        $tags = array(
            array('d', $post->post_name), // Use post slug as identifier
            array('title', $post->post_title),
            array('published_at', date('c', strtotime($post->post_date))),
            array('url', get_permalink($post_id))
        );
        
        // Add post categories as tags
        $categories = get_the_category($post_id);
        foreach ($categories as $category) {
            $tags[] = array('t', $category->name);
        }
        
        // Add post tags as tags
        $post_tags = get_the_tags($post_id);
        if ($post_tags) {
            foreach ($post_tags as $tag) {
                $tags[] = array('t', $tag->name);
            }
        }
        
        // Add featured image if exists
        $featured_image = get_the_post_thumbnail_url($post_id, 'full');
        if ($featured_image) {
            $tags[] = array('image', $featured_image);
        }
        
        $event = array(
            'kind' => 30023,
            'content' => $content,
            'tags' => $tags,
            'created_at' => strtotime($post->post_date)
        );
        
        return $event;
    }
    
    /**
     * Convert WordPress note to Nostr event (kind 1)
     */
    public function note_to_nostr_event($note_id) {
        $note = get_post($note_id);
        if (!$note || $note->post_type !== 'note') {
            return false;
        }
        
        // Check if sync is enabled for this note
        $sync_enabled = get_post_meta($note_id, '_nostr_sync_enabled', true);
        if (!$sync_enabled) {
            return false;
        }
        
        $content = wp_strip_all_tags($note->post_content);
        
        $tags = array();
        
        // Add note tags if they exist
        $note_tags = get_the_tags($note_id);
        if ($note_tags) {
            foreach ($note_tags as $tag) {
                $tags[] = array('t', $tag->name);
            }
        }
        
        $event = array(
            'kind' => 1,
            'content' => $content,
            'tags' => $tags,
            'created_at' => strtotime($note->post_date)
        );
        
        return $event;
    }
    
    /**
     * Convert Nostr event to WordPress post (kind 30023)
     */
    
    /**
     * Convert Nostr event to WordPress note (kind 1)
     */
    public function nostr_event_to_note($event, $user_id = null) {
        if ($event['kind'] !== 1) {
            return false;
        }
        
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        // Extract title from content (first line or first 50 chars)
        $content = $this->markdown_to_html($event['content']);
        $title = $this->extract_title_from_content($event['content']);
        
        $post_data = array(
            'post_title' => $title,
            'post_content' => $content,
            'post_status' => 'publish',
            'post_type' => 'note',
            'post_author' => $user_id,
            'post_date' => date('Y-m-d H:i:s', $event['created_at']),
            'post_date_gmt' => gmdate('Y-m-d H:i:s', $event['created_at'])
        );
        
        $post_id = wp_insert_post($post_data);
        
        if ($post_id && !is_wp_error($post_id)) {
            // Set sync metadata
            update_post_meta($post_id, '_nostr_sync_enabled', 1);
            update_post_meta($post_id, '_nostr_event_id', $event['id']);
            update_post_meta($post_id, '_nostr_synced_at', current_time('mysql'));
            update_post_meta($post_id, '_nostr_sync_status', 'synced');
            update_post_meta($post_id, '_nostr_sync_source', 'nostr');
            update_post_meta($post_id, '_nostr_last_modified', $event['created_at']);
            
            // Add tags from Nostr event
            $this->add_tags_to_note($post_id, $event['tags']);
            
            return $post_id;
        }
        
        return false;
    }
    
    /**
     * Convert Nostr event to WordPress post (kind 30023)
     */
    public function nostr_event_to_post($event, $user_id = null) {
        if ($event['kind'] !== 30023) {
            return false;
        }
        
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        // Extract title from tags or content
        $title = $this->extract_title_from_kind30023($event);
        $content = $this->markdown_to_html($event['content']);
        
        $post_data = array(
            'post_title' => $title,
            'post_content' => $content,
            'post_status' => 'publish',
            'post_type' => 'post', // Standard WordPress post
            'post_author' => $user_id,
            'post_date' => date('Y-m-d H:i:s', $event['created_at']),
            'post_date_gmt' => gmdate('Y-m-d H:i:s', $event['created_at'])
        );
        
        $post_id = wp_insert_post($post_data);
        
        if ($post_id && !is_wp_error($post_id)) {
            // Set sync metadata
            update_post_meta($post_id, '_nostr_sync_enabled', 1);
            update_post_meta($post_id, '_nostr_event_id', $event['id']);
            update_post_meta($post_id, '_nostr_synced_at', current_time('mysql'));
            update_post_meta($post_id, '_nostr_sync_status', 'synced');
            update_post_meta($post_id, '_nostr_sync_source', 'nostr');
            update_post_meta($post_id, '_nostr_last_modified', $event['created_at']);
            
            // Add tags from Nostr event
            $this->add_tags_to_post($post_id, $event['tags']);
            
            return $post_id;
        }
        
        return false;
    }
    
    /**
     * Extract title from kind 30023 event
     */
    private function extract_title_from_kind30023($event) {
        // Look for title in tags first
        if (isset($event['tags']) && is_array($event['tags'])) {
            foreach ($event['tags'] as $tag) {
                if (is_array($tag) && $tag[0] === 'title' && isset($tag[1])) {
                    return $tag[1];
                }
            }
        }
        
        // Fallback to first line of content
        return $this->extract_title_from_content($event['content']);
    }
    
    /**
     * Add tags to WordPress post
     */
    private function add_tags_to_post($post_id, $tags) {
        if (empty($tags) || !is_array($tags)) {
            return;
        }
        
        $wp_tags = array();
        foreach ($tags as $tag) {
            if (is_array($tag) && $tag[0] === 't' && isset($tag[1])) {
                $wp_tags[] = $tag[1];
            }
        }
        
        if (!empty($wp_tags)) {
            wp_set_post_tags($post_id, $wp_tags);
        }
    }
    
    /**
     * Extract title from content
     */
    private function extract_title_from_content($content) {
        // Get first line or first 50 characters
        $lines = explode("\n", $content);
        $first_line = trim($lines[0]);
        
        if (strlen($first_line) > 50) {
            return substr($first_line, 0, 47) . '...';
        }
        
        return $first_line ?: 'Note';
    }
    
    /**
     * Convert HTML to Markdown
     */
    private function html_to_markdown($html) {
        // Simple HTML to Markdown conversion
        // In production, you'd want to use a proper library like league/html-to-markdown
        
        $markdown = $html;
        
        // Convert basic HTML tags
        $markdown = preg_replace('/<h1[^>]*>(.*?)<\/h1>/i', '# $1', $markdown);
        $markdown = preg_replace('/<h2[^>]*>(.*?)<\/h2>/i', '## $1', $markdown);
        $markdown = preg_replace('/<h3[^>]*>(.*?)<\/h3>/i', '### $1', $markdown);
        $markdown = preg_replace('/<h4[^>]*>(.*?)<\/h4>/i', '#### $1', $markdown);
        $markdown = preg_replace('/<h5[^>]*>(.*?)<\/h5>/i', '##### $1', $markdown);
        $markdown = preg_replace('/<h6[^>]*>(.*?)<\/h6>/i', '###### $1', $markdown);
        
        $markdown = preg_replace('/<strong[^>]*>(.*?)<\/strong>/i', '**$1**', $markdown);
        $markdown = preg_replace('/<b[^>]*>(.*?)<\/b>/i', '**$1**', $markdown);
        $markdown = preg_replace('/<em[^>]*>(.*?)<\/em>/i', '*$1*', $markdown);
        $markdown = preg_replace('/<i[^>]*>(.*?)<\/i>/i', '*$1*', $markdown);
        
        $markdown = preg_replace('/<a[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/i', '[$2]($1)', $markdown);
        $markdown = preg_replace('/<img[^>]*src=["\']([^"\']*)["\'][^>]*>/i', '![image]($1)', $markdown);
        
        $markdown = preg_replace('/<ul[^>]*>(.*?)<\/ul>/is', '$1', $markdown);
        $markdown = preg_replace('/<ol[^>]*>(.*?)<\/ol>/is', '$1', $markdown);
        $markdown = preg_replace('/<li[^>]*>(.*?)<\/li>/i', '- $1', $markdown);
        
        $markdown = preg_replace('/<p[^>]*>(.*?)<\/p>/i', '$1', $markdown);
        $markdown = preg_replace('/<br[^>]*\/?>/i', "\n", $markdown);
        
        // Remove remaining HTML tags
        $markdown = strip_tags($markdown);
        
        // Clean up whitespace
        $markdown = preg_replace('/\n\s*\n/', "\n\n", $markdown);
        $markdown = trim($markdown);
        
        return $markdown;
    }
    
    /**
     * Convert Markdown to HTML
     */
    private function markdown_to_html($markdown) {
        // Enhanced Markdown to HTML conversion
        $html = $markdown;
        
        // Convert headers (must be first to avoid conflicts)
        $html = preg_replace('/^# (.*$)/m', '<h1>$1</h1>', $html);
        $html = preg_replace('/^## (.*$)/m', '<h2>$1</h2>', $html);
        $html = preg_replace('/^### (.*$)/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^#### (.*$)/m', '<h4>$1</h4>', $html);
        $html = preg_replace('/^##### (.*$)/m', '<h5>$1</h5>', $html);
        $html = preg_replace('/^###### (.*$)/m', '<h6>$1</h6>', $html);
        
        // Convert tables
        $html = $this->convert_markdown_tables($html);
        
        // Convert images FIRST (before links to avoid conflicts)
        $html = preg_replace('/!\[([^\]]*)\]\(([^)]+)\)/', '<img src="$2" alt="$1">', $html);
        
        // Convert standalone image URLs (jpg, png, gif) to images
        $html = preg_replace_callback('/\b(https?:\/\/[^\s]+\.(jpg|jpeg|png|gif|webp))\b/i', function($matches) {
            $url = $matches[1];
            $filename = basename(parse_url($url, PHP_URL_PATH));
            return '<img src="' . $url . '" alt="' . htmlspecialchars($filename) . '">';
        }, $html);
        
        // Convert links [text](url) - after images
        $html = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $html);
        
        // Convert standalone URLs to links (but not if they're already images)
        $html = preg_replace('/\b(https?:\/\/[^\s<>"]+)\b(?![^<]*>)/', '<a href="$1">$1</a>', $html);
        
        // Convert bold and italic (bold first to avoid conflicts)
        $html = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $html);
        $html = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $html);
        
        // Convert unordered lists
        $html = $this->convert_markdown_lists($html);
        
        // Convert line breaks (but preserve existing HTML)
        $html = preg_replace('/(?<!>)\n(?!<)/', '<br>', $html);
        
        return $html;
    }
    
    /**
     * Convert Markdown tables to HTML
     */
    private function convert_markdown_tables($html) {
        // Find table blocks
        $pattern = '/\|(.+)\|\s*\n\|[-\s|]+\|\s*\n((?:\|.+\|\s*\n?)*)/';
        
        return preg_replace_callback($pattern, function($matches) {
            $header_row = trim($matches[1]);
            $data_rows = trim($matches[2]);
            
            // Parse header (filter out empty cells from leading/trailing |)
            $headers = array_filter(array_map('trim', explode('|', $header_row)), function($cell) {
                return !empty($cell);
            });
            $header_html = '<thead><tr>';
            foreach ($headers as $header) {
                $header_html .= '<th>' . htmlspecialchars($header) . '</th>';
            }
            $header_html .= '</tr></thead>';
            
            // Parse data rows
            $data_html = '<tbody>';
            $rows = explode("\n", $data_rows);
            foreach ($rows as $row) {
                $row = trim($row);
                if (empty($row)) continue;
                
                // Filter out empty cells from leading/trailing |
                $cells = array_filter(array_map('trim', explode('|', $row)), function($cell) {
                    return !empty($cell);
                });
                $data_html .= '<tr>';
                foreach ($cells as $cell) {
                    $data_html .= '<td>' . htmlspecialchars($cell) . '</td>';
                }
                $data_html .= '</tr>';
            }
            $data_html .= '</tbody>';
            
            return '<table>' . $header_html . $data_html . '</table>';
        }, $html);
    }
    
    /**
     * Convert Markdown lists to HTML
     */
    private function convert_markdown_lists($html) {
        // Convert unordered lists
        $lines = explode("\n", $html);
        $in_list = false;
        $result = array();
        
        foreach ($lines as $line) {
            if (preg_match('/^- (.+)$/', $line, $matches)) {
                if (!$in_list) {
                    $result[] = '<ul>';
                    $in_list = true;
                }
                $result[] = '<li>' . $matches[1] . '</li>';
            } else {
                if ($in_list) {
                    $result[] = '</ul>';
                    $in_list = false;
                }
                $result[] = $line;
            }
        }
        
        if ($in_list) {
            $result[] = '</ul>';
        }
        
        return implode("\n", $result);
    }
    
    /**
     * Add tags from Nostr event to WordPress post
     */
    
    /**
     * Add tags from Nostr event to WordPress note
     */
    private function add_tags_to_note($note_id, $tags) {
        $tag_names = array();
        
        foreach ($tags as $tag) {
            if ($tag[0] === 't') {
                $tag_names[] = $tag[1];
            }
        }
        
        if (!empty($tag_names)) {
            wp_set_post_tags($note_id, $tag_names);
        }
    }
    
    /**
     * Get post by Nostr event ID
     */
    public function get_post_by_nostr_event_id($event_id) {
        $posts = get_posts(array(
            'meta_key' => '_nostr_event_id',
            'meta_value' => $event_id,
            'post_type' => array('post', 'note'),
            'posts_per_page' => 1
        ));
        
        return !empty($posts) ? $posts[0] : false;
    }
    
    /**
     * Check if content has changed since last sync
     */
    public function has_content_changed($post_id, $last_sync_time) {
        $post = get_post($post_id);
        if (!$post) {
            return false;
        }
        
        $post_modified = strtotime($post->post_modified);
        $last_sync = strtotime($last_sync_time);
        
        return $post_modified > $last_sync;
    }
}
