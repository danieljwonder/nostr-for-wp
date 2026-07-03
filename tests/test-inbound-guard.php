<?php
/**
 * Regression test: posts created BY inbound Nostr sync must NEVER be queued
 * for outbound NIP-46 publishing (loop guard). Not "queued then skipped" —
 * never queued at all.
 *
 * Uses the REAL Nostr_Content_Mapper create path and the REAL
 * Nostr_NIP46_Publisher, with a wp_insert_post() stub that reproduces
 * WordPress core's ordering exactly:
 *
 *   1. post row created
 *   2. meta_input written           <- the _nostr_origin stamp lands here
 *   3. transition_post_status fires <- the publisher's guard runs here
 *
 * If the _nostr_origin stamp ever moves out of meta_input (e.g. back to a
 * post-insert update_post_meta call), step 3 sees no origin meta and the
 * post gets queued — and this test fails.
 *
 * Standalone: no WordPress, no network, no mock bunker needed.
 */

require __DIR__ . '/wp-stubs.php';

define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);
define('WP_CONTENT_DIR', sys_get_temp_dir());

$GLOBALS['__meta'] = array();
$GLOBALS['__cron'] = array();
$GLOBALS['__posts'] = array();
$GLOBALS['__transients'] = array();
$GLOBALS['__status_history'] = array(); // post_id => list of _nostr_sync_status values ever written
$GLOBALS['__next_post_id'] = 100;

/* ---- WordPress stubs ---- */

function nostr_for_wp_debug_log($msg) {}
function get_post_meta($post_id, $key, $single = false) {
    return isset($GLOBALS['__meta'][$post_id][$key]) ? $GLOBALS['__meta'][$post_id][$key] : '';
}
function update_post_meta($post_id, $key, $value) {
    if ($key === '_nostr_sync_status') {
        $GLOBALS['__status_history'][$post_id][] = $value;
    }
    $GLOBALS['__meta'][$post_id][$key] = $value;
    return true;
}
function delete_post_meta($post_id, $key) {
    unset($GLOBALS['__meta'][$post_id][$key]);
    return true;
}
function get_post($id) {
    return isset($GLOBALS['__posts'][$id]) ? $GLOBALS['__posts'][$id] : null;
}
function wp_is_post_autosave($id) { return false; }
function wp_is_post_revision($id) { return false; }
function wp_schedule_single_event($ts, $hook, $args = array()) {
    $GLOBALS['__cron'][$hook . ':' . json_encode($args)] = $ts;
    return true;
}
function wp_clear_scheduled_hook($hook, $args = array()) {
    unset($GLOBALS['__cron'][$hook . ':' . json_encode($args)]);
}
function wp_next_scheduled($hook, $args = array()) {
    $k = $hook . ':' . json_encode($args);
    return isset($GLOBALS['__cron'][$k]) ? $GLOBALS['__cron'][$k] : false;
}
function get_transient($k) { return isset($GLOBALS['__transients'][$k]) ? $GLOBALS['__transients'][$k] : false; }
function set_transient($k, $v, $ttl = 0) { $GLOBALS['__transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['__transients'][$k]); return true; }
function spawn_cron() {}
function add_action() {}
function add_filter() {}
function current_time($t) { return date('Y-m-d H:i:s'); }
function get_current_user_id() { return 1; }
function post_type_exists($t) { return true; }
function get_post_type_object($t) { return null; }
function wp_set_post_tags($id, $tags = '', $append = false) { return true; }
function is_wp_error($thing) { return false; }
function __($s, $d = null) { return $s; }

/**
 * The heart of the test: reproduce core's wp_insert_post() ordering.
 * Core writes meta_input BEFORE firing wp_transition_post_status; the
 * NIP-46 publisher's guard runs inside that transition.
 */
function wp_insert_post($post_data, $wp_error = false) {
    $id = $GLOBALS['__next_post_id']++;

    $post = (object) array(
        'ID'          => $id,
        'post_type'   => $post_data['post_type'],
        'post_status' => $post_data['post_status'],
        'post_title'  => isset($post_data['post_title']) ? $post_data['post_title'] : '',
    );
    $GLOBALS['__posts'][$id] = $post;

    // (2) meta_input — exactly like core, before the transition.
    if (!empty($post_data['meta_input']) && is_array($post_data['meta_input'])) {
        foreach ($post_data['meta_input'] as $k => $v) {
            update_post_meta($id, $k, $v);
        }
    }

    // (3) transition_post_status — where Nostr_NIP46_Publisher listens.
    Nostr_NIP46_Publisher::get_instance()->handle_post_publish($post_data['post_status'], 'new', $post);

    return $id;
}

require NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-content-mapper.php';
require NOSTR_FOR_WP_PLUGIN_DIR . 'includes/class-nip46-publisher.php';

$fail = 0;
function check($label, $cond) {
    global $fail;
    echo ($cond ? 'PASS' : 'FAIL') . ": $label\n";
    if (!$cond) $fail++;
}

/* ---- make bunker mode fully active so the publisher WOULD queue ---- */
$settings = Nostr_NIP46_Settings::get_instance();
$settings->set_signing_method('nip46');
$settings->save_bunker_uri('bunker://' . str_repeat('ab', 32) . '?relay=wss://relay.example.com/&secret=s3cret');
$settings->save_connection_state(str_repeat('cd', 32), 'nip44');
check('precondition: bunker mode active', $settings->is_bunker_active());

/* Sanity: an ordinary WP-authored publish DOES queue (guard is not overbroad). */
wp_insert_post(array(
    'post_title'  => 'Native WP post',
    'post_status' => 'publish',
    'post_type'   => 'post',
));
$native_id = $GLOBALS['__next_post_id'] - 1;
check('control: native WP post IS queued', wp_next_scheduled(Nostr_NIP46_Publisher::CRON_HOOK, array($native_id)) !== false);

$mapper = Nostr_Content_Mapper::get_instance();

/* ---- inbound kind 1 -> note must never be queued ---- */
$note_event = array(
    'id'         => str_repeat('11', 32),
    'kind'       => 1,
    'content'    => 'An inbound note that must not echo back out.',
    'tags'       => array(),
    'created_at' => time(),
    'pubkey'     => str_repeat('cd', 32),
);
$note_id = $mapper->nostr_event_to_note($note_event, 1);
check('inbound note created', $note_id !== false && $note_id > 0);
check('inbound note has _nostr_origin', (bool) get_post_meta($note_id, '_nostr_origin', true));
check('inbound note: no cron attempt scheduled', wp_next_scheduled(Nostr_NIP46_Publisher::CRON_HOOK, array($note_id)) === false);
$history = isset($GLOBALS['__status_history'][$note_id]) ? $GLOBALS['__status_history'][$note_id] : array();
check('inbound note: status NEVER set to queued', !in_array('queued', $history, true));

/* ---- inbound kind 30023 -> post must never be queued ---- */
$article_event = array(
    'id'         => str_repeat('22', 32),
    'kind'       => 30023,
    'content'    => "# Inbound article\n\nBody text.",
    'tags'       => array(array('title', 'Inbound article'), array('d', 'inbound-article')),
    'created_at' => time(),
    'pubkey'     => str_repeat('cd', 32),
);
$post_id = $mapper->nostr_event_to_post($article_event, 1);
check('inbound post created', $post_id !== false && $post_id > 0);
check('inbound post has _nostr_origin', (bool) get_post_meta($post_id, '_nostr_origin', true));
check('inbound post: no cron attempt scheduled', wp_next_scheduled(Nostr_NIP46_Publisher::CRON_HOOK, array($post_id)) === false);
$history = isset($GLOBALS['__status_history'][$post_id]) ? $GLOBALS['__status_history'][$post_id] : array();
check('inbound post: status NEVER set to queued', !in_array('queued', $history, true));

echo $fail === 0 ? "\nALL INBOUND-GUARD CHECKS PASSED\n" : "\n$fail CHECKS FAILED\n";
exit($fail > 0 ? 1 : 0);
