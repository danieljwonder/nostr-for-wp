<?php
/**
 * Fetch a few real events from a public relay and check our event_id()
 * implementation against them. Not shipped with the plugin.
 */
define('ABSPATH', __DIR__ . '/');
define('NOSTR_FOR_WP_PLUGIN_DIR', dirname(__DIR__) . '/');

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/includes/class-websocket-client.php';
require dirname(__DIR__) . '/includes/class-nip46-crypto.php';

$relay = $argv[1] ?? 'wss://relay.damus.io';
$ws = new Nostr_WebSocket_Client($relay);
if (!$ws->connect()) {
    fwrite(STDERR, "connect failed\n");
    exit(1);
}
$sub = 'test' . bin2hex(random_bytes(4));
$ws->send_message(json_encode(array('REQ', $sub, array('kinds' => array(1), 'limit' => 15))));

$events = array();
$start = time();
while (time() - $start < 15 && count($events) < 15) {
    $msg = $ws->read_message();
    if (!$msg) { usleep(100000); continue; }
    $data = json_decode($msg, true);
    if (!is_array($data)) continue;
    if ($data[0] === 'EVENT' && $data[1] === $sub) {
        $events[] = $data[2];
    } elseif ($data[0] === 'EOSE') {
        break;
    }
}
$ws->close();

$pass = 0; $fail = 0;
foreach ($events as $ev) {
    $computed = Nostr_NIP46_Crypto::event_id($ev);
    $sig_ok = Nostr_NIP46_Crypto::schnorr_verify($ev['id'], $ev['sig'], $ev['pubkey']);
    if ($computed === $ev['id'] && $sig_ok) {
        $pass++;
    } else {
        $fail++;
        echo "MISMATCH id={$ev['id']} computed=$computed sig_ok=" . var_export($sig_ok, true) . "\n";
        echo "  content=" . json_encode(substr($ev['content'], 0, 120)) . "\n";
    }
}
echo count($events) . " events fetched, $pass id+sig verified, $fail failed\n";
exit($fail > 0 || count($events) === 0 ? 1 : 0);
