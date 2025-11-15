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
        $log_file = WP_CONTENT_DIR . '/nostr-debug.log';
        $event_id = $event['id'] ?? 'unknown';
        
        nostr_for_wp_debug_log("nostr_event_to_note() called for event {$event_id}");
        
        if ($event['kind'] !== 1) {
            // Always log errors
            $msg = date('Y-m-d H:i:s') . " - Event {$event_id}: nostr_event_to_note() FAILED - Wrong kind ({$event['kind']}, expected 1)\n";
            file_put_contents($log_file, $msg, FILE_APPEND | LOCK_EX);
            return false;
        }
        
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        nostr_for_wp_debug_log("Event {$event_id}: Using user_id: {$user_id}");
        
        // Extract title from content (first line or first 50 chars)
        $content = $this->markdown_to_html($event['content']);
        $title = $this->extract_title_from_content($event['content']);
        
        nostr_for_wp_debug_log("Event {$event_id}: Extracted title: " . substr($title, 0, 50) . "...");
        
        // Validate required fields
        if (empty($title)) {
            // Always log failures
            $msg = date('Y-m-d H:i:s') . " - Event {$event_id}: FAILED - Empty title extracted\n";
            file_put_contents($log_file, $msg, FILE_APPEND | LOCK_EX);
            return false;
        }
        
        if (empty($content)) {
            // Always log failures
            $msg = date('Y-m-d H:i:s') . " - Event {$event_id}: FAILED - Empty content after markdown conversion\n";
            file_put_contents($log_file, $msg, FILE_APPEND | LOCK_EX);
            return false;
        }
        
        // Final sanitization of title to ensure database compatibility
        $title = sanitize_text_field($title);
        // Remove any remaining invalid UTF-8 sequences
        if (function_exists('mb_convert_encoding')) {
            $title = mb_convert_encoding($title, 'UTF-8', 'UTF-8');
        }
        // Ensure title isn't empty
        if (empty($title)) {
            $title = 'Note';
        }
        
        $post_data = array(
            'post_title' => $title,
            'post_content' => $content,
            'post_status' => 'publish',
            'post_type' => 'note',
            'post_author' => $user_id,
            'post_date' => date('Y-m-d H:i:s', $event['created_at']),
            'post_date_gmt' => gmdate('Y-m-d H:i:s', $event['created_at'])
        );
        
        nostr_for_wp_debug_log("Event {$event_id}: Post data - title length: " . strlen($title) . ", content length: " . strlen($content) . ", author: {$user_id}");
        nostr_for_wp_debug_log("Event {$event_id}: Calling wp_insert_post() with post_type='note'");
        
        // Check if 'note' post type exists
        if (!post_type_exists('note')) {
            // Always log errors
            $msg = date('Y-m-d H:i:s') . " - Event {$event_id}: FAILED - 'note' post type does not exist!\n";
            file_put_contents($log_file, $msg, FILE_APPEND | LOCK_EX);
            return false;
        }
        
        // Check if user can create posts of this type
        $post_type_obj = get_post_type_object('note');
        if ($post_type_obj && isset($post_type_obj->cap)) {
            $capabilities = is_object($post_type_obj->cap) ? get_object_vars($post_type_obj->cap) : (array) $post_type_obj->cap;
            nostr_for_wp_debug_log("Event {$event_id}: Post type 'note' exists, capabilities: " . implode(', ', array_keys($capabilities)));
        }
        
        $post_id = wp_insert_post($post_data, true); // Set second param to true to get WP_Error if any
        
        // Check for PHP errors (always log these)
        $last_error = error_get_last();
        if ($last_error && in_array($last_error['type'], [E_ERROR, E_WARNING, E_PARSE, E_NOTICE, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_COMPILE_WARNING])) {
            $msg = date('Y-m-d H:i:s') . " - Event {$event_id}: PHP Error detected: " . $last_error['message'] . " in " . $last_error['file'] . ":" . $last_error['line'] . "\n";
            file_put_contents($log_file, $msg, FILE_APPEND | LOCK_EX);
        }
        
        nostr_for_wp_debug_log("Event {$event_id}: wp_insert_post() returned: " . var_export($post_id, true));
        
        if (is_wp_error($post_id)) {
            // Always log errors
            $msg = date('Y-m-d H:i:s') . " - Event {$event_id}: wp_insert_post() returned WP_Error: " . $post_id->get_error_message() . " (" . $post_id->get_error_code() . ")\n";
            file_put_contents($log_file, $msg, FILE_APPEND | LOCK_EX);
            return false;
        }
        
        if ($post_id && !is_wp_error($post_id)) {
            nostr_for_wp_debug_log("Event {$event_id}: Post created successfully (ID: {$post_id}), setting metadata...");
            
            // Set sync metadata
            update_post_meta($post_id, '_nostr_sync_enabled', 1);
            update_post_meta($post_id, '_nostr_event_id', $event['id']);
            update_post_meta($post_id, '_nostr_synced_at', current_time('mysql'));
            update_post_meta($post_id, '_nostr_sync_status', 'synced');
            update_post_meta($post_id, '_nostr_sync_source', 'nostr');
            update_post_meta($post_id, '_nostr_last_modified', $event['created_at']);
            
            // Add tags from Nostr event
            $this->add_tags_to_note($post_id, $event['tags']);
            
            nostr_for_wp_debug_log("Event {$event_id}: nostr_event_to_note() SUCCESS - Post ID: {$post_id}");
            
            return $post_id;
        }
        
        // Always log failures
        $msg = date('Y-m-d H:i:s') . " - Event {$event_id}: nostr_event_to_note() FAILED - wp_insert_post returned false/0 and no WP_Error\n";
        file_put_contents($log_file, $msg, FILE_APPEND | LOCK_EX);
        
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
        
        // Handle UTF-8 properly with mb_string functions
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($first_line, 'UTF-8') > 50) {
                $first_line = mb_substr($first_line, 0, 47, 'UTF-8') . '...';
            }
        } else {
            // Fallback for systems without mb_string
            if (strlen($first_line) > 50) {
                $first_line = substr($first_line, 0, 47) . '...';
            }
        }
        
        // Sanitize to ensure valid UTF-8 and remove invalid characters
        $first_line = sanitize_text_field($first_line);
        
        // Ensure we have a valid title (max 200 chars for WordPress)
        if (function_exists('mb_strlen')) {
            if (mb_strlen($first_line, 'UTF-8') > 200) {
                $first_line = mb_substr($first_line, 0, 197, 'UTF-8') . '...';
            }
        } else {
            if (strlen($first_line) > 200) {
                $first_line = substr($first_line, 0, 197) . '...';
            }
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
        
        // Resolve NIP-19 nprofile references to usernames (before other conversions)
        $html = $this->resolve_nprofile_references($html);
        
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
    
    /**
     * Resolve NIP-19 nprofile references to usernames with links
     * 
     * @param string $content Content that may contain nprofile references
     * @return string Content with nprofile references replaced by linked usernames
     */
    private function resolve_nprofile_references($content) {
        // Pattern to match nprofile1... Bech32 encoded strings
        // Also matches "nostr:nprofile1..." format and strips the "nostr:" prefix
        // nprofile strings start with nprofile1 and contain only Bech32 characters (qpzry9x8gf2tvdw0s3jn54khce6mua7l)
        // Minimum length is typically around 60 characters
        $pattern = '/\b(?:nostr:)?(nprofile1[qpzry9x8gf2tvdw0s3jn54khce6mua7l]{58,})\b/i';
        
        return preg_replace_callback($pattern, function($matches) {
            $nprofile = $matches[1];
            $username = $this->get_username_from_nprofile($nprofile);
            
            // Create link to njump.me profile
            $url = 'https://njump.me/' . esc_attr($nprofile);
            
            // If we got a username, use @username as anchor text
            // Otherwise, use a shortened version of the nprofile
            if ($username) {
                $anchor_text = '@' . esc_html($username);
            } else {
                // Use shortened nprofile as fallback (first 20 chars + ...)
                $anchor_text = esc_html(substr($nprofile, 0, 20) . '...');
            }
            
            return '<a href="' . $url . '" class="nostr-profile-link" rel="nofollow" target="_blank">' . $anchor_text . '</a>';
        }, $content);
    }
    
    /**
     * Get username from NIP-19 nprofile Bech32 string
     * 
     * @param string $nprofile Bech32 encoded nprofile string
     * @return string|false Username if found, false otherwise
     */
    private function get_username_from_nprofile($nprofile) {
        // Check cache first
        $cache_key = 'nostr_nprofile_' . md5($nprofile);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }
        
        // Decode the nprofile
        $decoded = $this->decode_nprofile($nprofile);
        if (!$decoded || !isset($decoded['pubkey'])) {
            // Cache negative result for 1 hour to avoid repeated failed lookups
            set_transient($cache_key, false, HOUR_IN_SECONDS);
            return false;
        }
        
        $pubkey = $decoded['pubkey'];
        $relays = isset($decoded['relays']) ? $decoded['relays'] : array();
        
        // Fetch profile from Nostr relays
        $username = $this->fetch_profile_username($pubkey, $relays);
        
        // Cache the result (24 hours if found, 1 hour if not found)
        $cache_duration = $username ? DAY_IN_SECONDS : HOUR_IN_SECONDS;
        set_transient($cache_key, $username, $cache_duration);
        
        return $username;
    }
    
    /**
     * Decode NIP-19 nprofile Bech32 string
     * 
     * @param string $nprofile Bech32 encoded nprofile string
     * @return array|false Decoded data with 'pubkey' and optionally 'relays', or false on failure
     */
    private function decode_nprofile($nprofile) {
        // Basic validation
        if (strpos($nprofile, 'nprofile1') !== 0) {
            return false;
        }
        
        // Decode Bech32
        $decoded = $this->bech32_decode($nprofile);
        if (!$decoded) {
            return false;
        }
        
        list($hrp, $data) = $decoded;
        
        // Convert from 5-bit to 8-bit
        $bytes = $this->convert_bits($data, 5, 8, false);
        if (!$bytes || count($bytes) < 33) {
            return false;
        }
        
        // Extract TLV data
        $result = array();
        $i = 0;
        
        while ($i < count($bytes)) {
            if ($i + 1 >= count($bytes)) {
                break;
            }
            
            $type = $bytes[$i];
            $length = $bytes[$i + 1];
            
            if ($i + 2 + $length > count($bytes)) {
                break;
            }
            
            $value = array_slice($bytes, $i + 2, $length);
            
            // Type 0 = pubkey (32 bytes)
            if ($type === 0 && $length === 32) {
                $pubkey = '';
                foreach ($value as $byte) {
                    $pubkey .= sprintf('%02x', $byte);
                }
                $result['pubkey'] = $pubkey;
            }
            
            // Type 1 = relay URL (variable length)
            if ($type === 1) {
                $relay = '';
                foreach ($value as $byte) {
                    $relay .= chr($byte);
                }
                if (!isset($result['relays'])) {
                    $result['relays'] = array();
                }
                $result['relays'][] = $relay;
            }
            
            $i += 2 + $length;
        }
        
        if (!isset($result['pubkey'])) {
            return false;
        }
        
        return $result;
    }
    
    /**
     * Bech32 decode implementation
     * 
     * @param string $str Bech32 encoded string
     * @return array|false Array with [hrp, data] or false on failure
     */
    private function bech32_decode($str) {
        // Bech32 character set
        $charset = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
        
        // Convert to lowercase
        $str = strtolower($str);
        
        // Find the last '1' separator
        $pos = strrpos($str, '1');
        if ($pos === false || $pos < 1 || $pos + 7 > strlen($str)) {
            return false;
        }
        
        $hrp = substr($str, 0, $pos);
        $data_part = substr($str, $pos + 1);
        
        // Validate HRP (should be 'nprofile')
        if ($hrp !== 'nprofile') {
            return false;
        }
        
        // Decode data part
        $data = array();
        for ($i = 0; $i < strlen($data_part); $i++) {
            $char = $data_part[$i];
            $pos_in_charset = strpos($charset, $char);
            if ($pos_in_charset === false) {
                return false;
            }
            $data[] = $pos_in_charset;
        }
        
        // Verify checksum
        if (!$this->bech32_verify_checksum($hrp, $data)) {
            return false;
        }
        
        // Remove checksum (last 6 characters)
        $data = array_slice($data, 0, -6);
        
        return array($hrp, $data);
    }
    
    /**
     * Verify Bech32 checksum
     * 
     * @param string $hrp Human-readable part
     * @param array $data Data array
     * @return bool True if checksum is valid
     */
    private function bech32_verify_checksum($hrp, $data) {
        return $this->bech32_polymod(array_merge($this->bech32_hrp_expand($hrp), $data)) === 1;
    }
    
    /**
     * Expand HRP for checksum calculation
     * 
     * @param string $hrp Human-readable part
     * @return array Expanded HRP values
     */
    private function bech32_hrp_expand($hrp) {
        $result = array();
        for ($i = 0; $i < strlen($hrp); $i++) {
            $result[] = ord($hrp[$i]) >> 5;
        }
        $result[] = 0;
        for ($i = 0; $i < strlen($hrp); $i++) {
            $result[] = ord($hrp[$i]) & 31;
        }
        return $result;
    }
    
    /**
     * Bech32 polymod function for checksum
     * 
     * @param array $values Values to process
     * @return int Polymod result
     */
    private function bech32_polymod($values) {
        $generator = array(0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3);
        $chk = 1;
        foreach ($values as $value) {
            $top = $chk >> 25;
            $chk = ($chk & 0x1ffffff) << 5 ^ $value;
            for ($i = 0; $i < 5; $i++) {
                if (($top >> $i) & 1) {
                    $chk ^= $generator[$i];
                }
            }
        }
        return $chk;
    }
    
    /**
     * Convert bits from one base to another
     * 
     * @param array $data Input data
     * @param int $frombits Source base
     * @param int $tobits Target base
     * @param bool $pad Whether to pad output
     * @return array|false Converted data or false on failure
     */
    private function convert_bits($data, $frombits, $tobits, $pad = true) {
        $acc = 0;
        $bits = 0;
        $ret = array();
        $maxv = (1 << $tobits) - 1;
        $max_acc = (1 << ($frombits + $tobits - 1)) - 1;
        
        foreach ($data as $value) {
            if ($value < 0 || ($value >> $frombits) != 0) {
                return false;
            }
            $acc = (($acc << $frombits) | $value) & $max_acc;
            $bits += $frombits;
            
            while ($bits >= $tobits) {
                $bits -= $tobits;
                $ret[] = (($acc >> $bits) & $maxv);
            }
        }
        
        if ($pad) {
            if ($bits) {
                $ret[] = (($acc << ($tobits - $bits)) & $maxv);
            }
        } elseif ($bits >= $frombits || ((($acc << ($tobits - $bits)) & $maxv) != 0)) {
            return false;
        }
        
        return $ret;
    }
    
    /**
     * Fetch profile username from Nostr relays
     * 
     * @param string $pubkey Public key (hex)
     * @param array $preferred_relays Optional preferred relays from nprofile
     * @return string|false Username/name if found, false otherwise
     */
    private function fetch_profile_username($pubkey, $preferred_relays = array()) {
        $nostr_client = Nostr_Client::get_instance();
        
        // Query for kind 0 (profile/metadata) events
        $filters = array(
            'kinds' => array(0),
            'authors' => array($pubkey),
            'limit' => 1
        );
        
        try {
            // If preferred relays are provided, we need to query them directly
            // Otherwise use the public subscribe_to_events method
            if (!empty($preferred_relays)) {
                // For preferred relays, we'll need to query them individually
                // Since query_relay is private, we'll use a workaround by temporarily
                // setting relays and using subscribe_to_events, or we can make a direct query
                // For now, let's use the default relays and hope the profile is available
                // In a production environment, you might want to make query_relay protected/public
                $events = $nostr_client->subscribe_to_events($filters);
            } else {
                $events = $nostr_client->subscribe_to_events($filters);
            }
            
            if (empty($events)) {
                return false;
            }
            
            // Get the most recent profile event
            $profile_event = $events[0];
            if (!isset($profile_event['content'])) {
                return false;
            }
            
            // Parse JSON content
            $profile_data = json_decode($profile_event['content'], true);
            if (!$profile_data || !is_array($profile_data)) {
                return false;
            }
            
            // Extract username/name (prefer 'name', fallback to 'display_name')
            if (isset($profile_data['name']) && !empty($profile_data['name'])) {
                return sanitize_text_field($profile_data['name']);
            }
            
            if (isset($profile_data['display_name']) && !empty($profile_data['display_name'])) {
                return sanitize_text_field($profile_data['display_name']);
            }
            
            // If no name found, return false
            return false;
            
        } catch (Exception $e) {
            error_log('Nostr: Error fetching profile username: ' . $e->getMessage());
            return false;
        }
    }
}
