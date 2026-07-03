<?php
/**
 * Standalone test harness for Nostr_NIP46_Crypto against official vectors.
 * Not shipped with the plugin. Run: php tests/test-crypto.php
 */

define('ABSPATH', __DIR__ . '/');
define('NOSTR_FOR_WP_PLUGIN_DIR', dirname(__DIR__) . '/');

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/includes/class-nip46-crypto.php';

$pass = 0;
$fail = 0;

function check($label, $cond) {
    global $pass, $fail;
    if ($cond) {
        $pass++;
    } else {
        $fail++;
        echo "FAIL: $label\n";
    }
}

/* ---------------- NIP-44 vectors ---------------- */
$v = json_decode(file_get_contents(__DIR__ . '/nip44.vectors.json'), true);
$valid = $v['v2']['valid'];

foreach ($valid['get_conversation_key'] as $t) {
    $ck = Nostr_NIP46_Crypto::nip44_conversation_key($t['sec1'], $t['pub2']);
    check("conv_key {$t['pub2']}", bin2hex($ck) === $t['conversation_key']);
}

foreach ($valid['encrypt_decrypt'] as $t) {
    $ck1 = Nostr_NIP46_Crypto::nip44_conversation_key($t['sec1'], Nostr_NIP46_Crypto::pubkey_from_privkey($t['sec2']));
    $ck2 = Nostr_NIP46_Crypto::nip44_conversation_key($t['sec2'], Nostr_NIP46_Crypto::pubkey_from_privkey($t['sec1']));
    check("conv_key sym", bin2hex($ck1) === $t['conversation_key'] && bin2hex($ck2) === $t['conversation_key']);
    $payload = Nostr_NIP46_Crypto::nip44_encrypt($t['plaintext'], $ck1, hex2bin($t['nonce']));
    check("encrypt {$t['nonce']}", $payload === $t['payload']);
    $pt = Nostr_NIP46_Crypto::nip44_decrypt($t['payload'], $ck2);
    check("decrypt {$t['nonce']}", $pt === $t['plaintext']);
}

foreach ($v['v2']['invalid']['decrypt'] as $t) {
    $threw = false;
    try {
        Nostr_NIP46_Crypto::nip44_decrypt($t['payload'], hex2bin($t['conversation_key']));
    } catch (Exception $e) {
        $threw = true;
    }
    check("invalid decrypt: {$t['note']}", $threw);
}

foreach ($v['v2']['invalid']['get_conversation_key'] as $t) {
    $threw = false;
    try {
        Nostr_NIP46_Crypto::nip44_conversation_key($t['sec1'], $t['pub2']);
    } catch (Exception $e) {
        $threw = true;
    }
    check("invalid conv_key: {$t['note']}", $threw);
}

/* ---------------- BIP-340 vectors ---------------- */
$rows = array_map('str_getcsv', file(__DIR__ . '/bip340-vectors.csv'));
array_shift($rows);
foreach ($rows as $r) {
    list($idx, $seckey, $pubkey, $aux, $msg, $sig, $result) = array_map('trim', array_slice($r, 0, 7));
    $expected = strtolower($result) === 'true';
    if (strlen($msg) !== 64) {
        continue; // our API signs 32-byte hashes only (event ids)
    }
    $ok = Nostr_NIP46_Crypto::schnorr_verify($msg, strtolower($sig), strtolower($pubkey));
    check("bip340 verify #$idx", $ok === $expected);

    if ($seckey !== '') {
        $derived = Nostr_NIP46_Crypto::pubkey_from_privkey($seckey);
        check("bip340 pubkey #$idx", $derived === strtolower($pubkey));
        // our signer uses random aux, so verify round-trip instead of exact sig
        $oursig = Nostr_NIP46_Crypto::schnorr_sign($msg, $seckey);
        check("bip340 sign-roundtrip #$idx", Nostr_NIP46_Crypto::schnorr_verify($msg, $oursig, $derived));
    }
}

/* ---------------- NIP-04 round trip ---------------- */
$a = Nostr_NIP46_Crypto::generate_keypair();
$b = Nostr_NIP46_Crypto::generate_keypair();
$ct = Nostr_NIP46_Crypto::nip04_encrypt('hello nip04', $a['privkey'], $b['pubkey']);
check('nip04 roundtrip', Nostr_NIP46_Crypto::nip04_decrypt($ct, $b['privkey'], $a['pubkey']) === 'hello nip04');

/* ---------------- Event id (NIP-01) ----------------
 * Covered by tests/fetch-event.php which validates event_id() and
 * schnorr_verify() against real signed events from a live relay. */

/* ---------------- npub encode ---------------- */
check('npub', Nostr_NIP46_Crypto::npub_encode('3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d') === 'npub180cvv07tjdrrgpa0j7j7tmnyl2yr6yr7l8j4s3evf6u64th6gkwsyjh6w6');

/* ---------------- finalize_event self-check ---------------- */
$kp = Nostr_NIP46_Crypto::generate_keypair();
$ev = Nostr_NIP46_Crypto::finalize_event(24133, 'test-content', array(array('p', $b['pubkey'])), $kp['privkey']);
check('finalize pubkey', $ev['pubkey'] === $kp['pubkey']);
check('finalize id', $ev['id'] === Nostr_NIP46_Crypto::event_id($ev));
check('finalize sig', Nostr_NIP46_Crypto::schnorr_verify($ev['id'], $ev['sig'], $ev['pubkey']));

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
