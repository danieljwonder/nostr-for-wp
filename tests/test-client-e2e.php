<?php
/**
 * End-to-end test of Nostr_NIP46_Client against a running bunker
 * (real `nak bunker` or tests/mock-bunker.php) over a real relay.
 *
 * Usage: php tests/test-client-e2e.php '<bunker://...>' [expected_user_pubkey]
 */

require __DIR__ . '/wp-stubs.php';

// Read the bunker URI from the mock bunker's output file (or argv) so the
// secret never appears on a command line.
$uri = $argv[1] ?? '';
$expected_pubkey = $argv[2] ?? null;
if (!$uri) {
    $out = @file_get_contents(__DIR__ . '/bunker-out.txt');
    if ($out && preg_match('/^BUNKER_URI=(\S+)/m', $out, $m)) {
        $uri = $m[1];
    }
    if (preg_match('/^USER_PUBKEY=(\S+)/m', (string) $out, $m)) {
        $expected_pubkey = $m[1];
    }
}
if (!$uri) {
    fwrite(STDERR, "usage: php test-client-e2e.php ['<bunker://uri>' [expected_pubkey]]\n");
    exit(1);
}

$fail = 0;
function check($label, $cond) {
    global $fail;
    echo ($cond ? 'PASS' : 'FAIL') . ": $label\n";
    if (!$cond) $fail++;
}

$settings = Nostr_NIP46_Settings::get_instance();
$settings->set_signing_method('nip46');
$settings->save_bunker_uri($uri);

$client = new Nostr_NIP46_Client($settings);

// 1. connect + get_public_key
$result = $client->connect_and_test();
echo "user_pubkey: {$result['user_pubkey']}\n";
echo "npub:        {$result['npub']}\n";
echo "encryption:  {$result['encryption']}\n";
check('connect returns 64-hex pubkey', (bool) preg_match('/^[0-9a-f]{64}$/', $result['user_pubkey']));
if ($expected_pubkey) {
    check('pubkey matches bunker identity', strtolower($expected_pubkey) === $result['user_pubkey']);
}

// Pace the RPCs: public relays rate-limit rapid publishes from one IP
// (both the test bunker and this client publish from this machine).
sleep(5);

// 2. re-run connect_and_test — secret is single-use, so this must skip
//    `connect` and still succeed via get_public_key.
$result2 = $client->connect_and_test();
check('second connect_and_test (secret already used)', $result2['user_pubkey'] === $result['user_pubkey']);
sleep(5);

// 3. ping
check('ping -> pong', $client->ping());
sleep(5);

// 4. sign a kind 1 event
$signed = $client->sign_event(array(
    'kind'       => 1,
    'content'    => 'nostr-for-wp NIP-46 e2e test note',
    'tags'       => array(array('t', 'test')),
    'created_at' => time(),
));
check('sign_event kind 1: pubkey is user pubkey', strtolower($signed['pubkey']) === $result['user_pubkey']);
check('sign_event kind 1: id valid', Nostr_NIP46_Crypto::event_id($signed) === $signed['id']);
check('sign_event kind 1: sig valid', Nostr_NIP46_Crypto::schnorr_verify($signed['id'], $signed['sig'], $signed['pubkey']));
sleep(5);

// 5. sign a kind 30023 event
$signed2 = $client->sign_event(array(
    'kind'       => 30023,
    'content'    => "# E2E\n\nlong-form test",
    'tags'       => array(array('d', 'nostr-for-wp-e2e'), array('title', 'E2E test')),
    'created_at' => time(),
));
check('sign_event kind 30023: sig valid', Nostr_NIP46_Crypto::schnorr_verify($signed2['id'], $signed2['sig'], $signed2['pubkey']));
check('distinct events have distinct ids', $signed['id'] !== $signed2['id']);

echo $fail === 0 ? "\nALL E2E CHECKS PASSED\n" : "\n$fail E2E CHECKS FAILED\n";
exit($fail > 0 ? 1 : 0);
