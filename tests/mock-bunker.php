<?php
/**
 * Minimal NIP-46 remote signer ("bunker") for end-to-end testing of the
 * plugin's NIP-46 client over a real relay. Speaks the same protocol as
 * `nak bunker`: kind 24133 request/response, NIP-44 (or NIP-04) payloads,
 * single-use connect secret. Not shipped with the plugin.
 *
 * Usage: php tests/mock-bunker.php [relay] [runtime_seconds]
 * Prints the bunker:// URI on the first line, then serves requests.
 */

require __DIR__ . '/wp-stubs.php';

$relay = isset($argv[1]) ? $argv[1] : 'wss://relay.damus.io';
$runtime = isset($argv[2]) ? intval($argv[2]) : 180;

$user = Nostr_NIP46_Crypto::generate_keypair();   // the "nsec in the bunker"
$signer = $user;                                   // remote-signer-key == user key (like nak's default)
$secret = bin2hex(random_bytes(8));
$secret_used = false;

echo 'BUNKER_URI=bunker://' . $signer['pubkey'] . '?relay=' . rawurlencode($relay) . '&secret=' . $secret . "\n";
echo 'USER_PUBKEY=' . $user['pubkey'] . "\n";
flush();

$ws = new Nostr_NIP46_WebSocket($relay);
$ws->connect(10);
$sub = 'bunker-' . bin2hex(random_bytes(4));
$ws->send_text(json_encode(array('REQ', $sub, array(
    'kinds' => array(24133),
    '#p'    => array($signer['pubkey']),
    'since' => time() - 5,
))));
echo "LISTENING\n";
flush();

$deadline = time() + $runtime;
while (time() < $deadline) {
    $raw = $ws->receive(1.0);
    if ($raw === null) {
        if (!$ws->is_connected()) {
            fwrite(STDERR, "relay connection lost, reconnecting\n");
            $ws = new Nostr_NIP46_WebSocket($relay);
            $ws->connect(10);
            $ws->send_text(json_encode(array('REQ', $sub, array(
                'kinds' => array(24133),
                '#p'    => array($signer['pubkey']),
                'since' => time() - 5,
            ))));
        }
        continue;
    }
    $msg = json_decode($raw, true);
    if (!is_array($msg)) {
        continue;
    }
    if ($msg[0] === 'OK' || $msg[0] === 'NOTICE' || $msg[0] === 'CLOSED') {
        // Log relay feedback on our own publishes (rate limits etc.)
        fwrite(STDERR, 'relay says: ' . substr($raw, 0, 200) . "\n");
        continue;
    }
    if ($msg[0] !== 'EVENT' || !isset($msg[2])) {
        continue;
    }
    $event = $msg[2];
    if ((int) $event['kind'] !== 24133) {
        continue;
    }
    $client_pubkey = strtolower($event['pubkey']);

    // Verify the request envelope.
    if (Nostr_NIP46_Crypto::event_id($event) !== $event['id'] ||
        !Nostr_NIP46_Crypto::schnorr_verify($event['id'], $event['sig'], $event['pubkey'])) {
        fwrite(STDERR, "bad request envelope\n");
        continue;
    }

    // Decrypt (detect nip04 vs nip44 from the payload shape).
    $used_nip04 = (strpos($event['content'], '?iv=') !== false);
    try {
        if ($used_nip04) {
            $plaintext = Nostr_NIP46_Crypto::nip04_decrypt($event['content'], $signer['privkey'], $client_pubkey);
        } else {
            $ck = Nostr_NIP46_Crypto::nip44_conversation_key($signer['privkey'], $client_pubkey);
            $plaintext = Nostr_NIP46_Crypto::nip44_decrypt($event['content'], $ck);
        }
    } catch (Exception $e) {
        fwrite(STDERR, 'decrypt failed: ' . $e->getMessage() . "\n");
        continue;
    }

    $req = json_decode($plaintext, true);
    if (!is_array($req) || !isset($req['id'], $req['method'])) {
        continue;
    }
    $params = isset($req['params']) ? $req['params'] : array();
    fwrite(STDERR, 'request: ' . $req['method'] . "\n");

    $result = '';
    $error = null;
    switch ($req['method']) {
        case 'connect':
            $given = isset($params[1]) ? $params[1] : '';
            if ($secret !== '' && !$secret_used) {
                if (hash_equals($secret, $given)) {
                    $secret_used = true;
                    $result = 'ack';
                } else {
                    $error = 'invalid secret';
                }
            } elseif ($secret_used) {
                $error = 'secret already used';
            } else {
                $result = 'ack';
            }
            break;
        case 'get_public_key':
            $result = $user['pubkey'];
            break;
        case 'ping':
            $result = 'pong';
            break;
        case 'sign_event':
            $unsigned = json_decode(isset($params[0]) ? $params[0] : '', true);
            if (!is_array($unsigned)) {
                $error = 'malformed event';
                break;
            }
            $signed = array(
                'kind'       => (int) $unsigned['kind'],
                'content'    => (string) $unsigned['content'],
                'tags'       => isset($unsigned['tags']) ? $unsigned['tags'] : array(),
                'created_at' => (int) $unsigned['created_at'],
                'pubkey'     => $user['pubkey'],
            );
            $signed['id'] = Nostr_NIP46_Crypto::event_id($signed);
            $signed['sig'] = Nostr_NIP46_Crypto::schnorr_sign($signed['id'], $user['privkey']);
            $result = json_encode($signed);
            break;
        default:
            $error = 'unsupported method: ' . $req['method'];
    }

    $response = array('id' => $req['id']);
    if ($error !== null) {
        $response['error'] = $error;
    } else {
        $response['result'] = $result;
    }
    $payload = json_encode($response);

    // Answer with the same encryption the client used.
    if ($used_nip04) {
        $content = Nostr_NIP46_Crypto::nip04_encrypt($payload, $signer['privkey'], $client_pubkey);
    } else {
        $ck = Nostr_NIP46_Crypto::nip44_conversation_key($signer['privkey'], $client_pubkey);
        $content = Nostr_NIP46_Crypto::nip44_encrypt($payload, $ck);
    }
    $response_event = Nostr_NIP46_Crypto::finalize_event(24133, $content, array(array('p', $client_pubkey)), $signer['privkey']);
    $ws->send_text(json_encode(array('EVENT', $response_event)));
    fwrite(STDERR, 'responded to ' . $req['method'] . "\n");
}

$ws->close();
echo "DONE\n";
