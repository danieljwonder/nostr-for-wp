<?php
/**
 * Unit checks for Nostr_NIP46_Settings: URI parsing/validation, secret
 * encryption at rest, keypair persistence and reset. Not shipped.
 */
require __DIR__ . '/wp-stubs.php';

$fail = 0;
function check($label, $cond) {
    global $fail;
    echo ($cond ? 'PASS' : 'FAIL') . ": $label\n";
    if (!$cond) $fail++;
}

$s = Nostr_NIP46_Settings::get_instance();

// URI parsing
$pk = str_repeat('ab', 32);
$parsed = Nostr_NIP46_Settings::parse_bunker_uri("bunker://$pk?relay=wss%3A%2F%2Fr1.example.com&relay=wss://r2.example.com&secret=s3cr3t-token");
check('parse pubkey', $parsed['remote_signer_pubkey'] === $pk);
check('parse relays', $parsed['relays'] === array('wss://r1.example.com', 'wss://r2.example.com'));
check('parse secret', $parsed['secret'] === 's3cr3t-token');

foreach (array(
    'not a uri',
    'bunker://tooshort?relay=wss://r.example.com',
    "bunker://$pk",                                  // no relay
    "bunker://$pk?relay=http://not-ws.example.com",  // bad scheme
    "nostrconnect://$pk?relay=wss://r.example.com",  // wrong scheme
) as $bad) {
    $threw = false;
    try { Nostr_NIP46_Settings::parse_bunker_uri($bad); } catch (Exception $e) { $threw = true; }
    check('rejects invalid uri', $threw);
}

// Keypair persistence + secret storage
check('can_encrypt', $s->can_encrypt());
$kp1 = $s->get_client_keypair();
$kp2 = $s->get_client_keypair();
check('keypair persisted (no silent regeneration)', $kp1 === $kp2);
check('pubkey derivable', Nostr_NIP46_Crypto::pubkey_from_privkey($kp1['privkey']) === $kp1['pubkey']);

$uri = "bunker://$pk?relay=wss://r1.example.com&secret=super-secret-value";
$s->save_bunker_uri($uri);
check('uri round trip', $s->get_bunker_uri() === $uri);

// The "database" must not contain the plaintext secret or private key.
$db_dump = json_encode($GLOBALS['__options']);
check('no plaintext uri secret in stored options', strpos($db_dump, 'super-secret-value') === false);
check('no plaintext client privkey in stored options', strpos($db_dump, $kp1['privkey']) === false);
check('non-secret parts visible for display', $s->get_bunker_display_info()['remote_signer_pubkey'] === $pk);

// Reset discards everything
$s->reset_client_keypair();
check('reset clears pubkey', $s->get_client_pubkey() === null);
check('reset clears uri', $s->get_bunker_uri() === null);
$kp3 = $s->get_client_keypair();
check('new keypair after reset', $kp3['pubkey'] !== $kp1['pubkey']);

echo $fail === 0 ? "\nALL SETTINGS CHECKS PASSED\n" : "\n$fail CHECKS FAILED\n";
exit($fail > 0 ? 1 : 0);
