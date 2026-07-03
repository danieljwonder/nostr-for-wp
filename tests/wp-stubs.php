<?php
/**
 * Minimal WordPress function stubs so the NIP-46 classes can be exercised
 * from the CLI without a WordPress install. Not shipped with the plugin.
 */

define('ABSPATH', __DIR__ . '/');
define('NOSTR_FOR_WP_PLUGIN_DIR', dirname(__DIR__) . '/');
define('NOSTR_FOR_WP_VERSION', 'test');
define('AUTH_KEY', 'test-auth-key-0123456789abcdef0123456789abcdef');
define('AUTH_SALT', 'test-auth-salt-fedcba9876543210');

$GLOBALS['__options'] = array();

function get_option($key, $default = false) {
    return isset($GLOBALS['__options'][$key]) ? $GLOBALS['__options'][$key] : $default;
}
function update_option($key, $value, $autoload = null) {
    $GLOBALS['__options'][$key] = $value;
    return true;
}
function wp_json_encode($data, $flags = 0) {
    return json_encode($data, $flags);
}
function wp_parse_url($url) {
    return parse_url($url);
}
function sanitize_text_field($str) {
    return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $str)));
}
function esc_url_raw($url) {
    return filter_var($url, FILTER_SANITIZE_URL);
}

require NOSTR_FOR_WP_PLUGIN_DIR . 'vendor/autoload.php';
require NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-nip46-crypto.php';
require NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-nip46-websocket.php';
require NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-nip46-settings.php';
require NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-nip46-client.php';
