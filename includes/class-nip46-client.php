<?php
/**
 * NIP-46 (Nostr Connect) remote signer client.
 *
 * Implements the client side of
 * https://github.com/nostr-protocol/nips/blob/master/46.md against a
 * remote signer ("bunker") such as `nak bunker`, nsec.app or Amber.
 *
 * MESSAGE FLOW (documented here for future maintainers):
 *
 *   1. The plugin holds a persistent *client keypair* (see
 *      Nostr_NIP46_Settings). The user's real key lives only in the bunker.
 *
 *   2. The user pastes a `bunker://<remote-signer-pubkey>?relay=..&secret=..`
 *      URI. The relays in that URI are the rendezvous point — both the
 *      bunker and this client connect OUTBOUND to them, so neither side
 *      needs an open inbound port.
 *
 *   3. Every RPC is a JSON object {id, method, params} which is encrypted
 *      (NIP-44, or NIP-04 for old signers) to the remote-signer-pubkey and
 *      wrapped in a kind 24133 event:
 *
 *          {
 *            "kind": 24133,
 *            "pubkey": <client-pubkey>,
 *            "content": <encrypted {id, method, params}>,
 *            "tags": [["p", <remote-signer-pubkey>]],
 *            ...id/sig signed with the client key
 *          }
 *
 *      The envelope is signed with the CLIENT key (relays reject unsigned
 *      events); the user's key is never present on this server.
 *
 *   4. The client subscribes to kind 24133 events p-tagging the client
 *      pubkey, publishes the request, and waits for the response event
 *      from the signer. The response content decrypts to
 *      {id, result, error}; ids are matched to correlate.
 *
 *   5. Session establishment: `connect` (params [remote-signer-pubkey,
 *      secret]) consumes the URI's single-use secret, then
 *      `get_public_key` returns the *user* pubkey that all signed events
 *      will carry. Both are persisted; subsequent signing operations skip
 *      the handshake.
 *
 *   6. `sign_event` sends the unsigned event JSON ({kind, content, tags,
 *      created_at}); the bunker returns the fully signed event, which is
 *      verified here (id recomputed + Schnorr signature + expected pubkey)
 *      before it is trusted.
 *
 * PHP is request-scoped, so a fresh WebSocket connection is opened per
 * signing operation and closed afterwards; there are no long-running
 * processes and no inbound endpoints (managed-hosting friendly, outbound
 * wss:// required).
 *
 * If the signer answers with result "auth_url" the bunker wants the user
 * to authenticate out-of-band (e.g. nsec.app confirmation page). The URL
 * is surfaced to the admin via Nostr_NIP46_Auth_Required_Exception.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thrown when the signer requires out-of-band user authorisation.
 */
class Nostr_NIP46_Auth_Required_Exception extends Exception {

    /**
     * @var string
     */
    public $auth_url;

    public function __construct($auth_url) {
        $this->auth_url = $auth_url;
        parent::__construct('The remote signer requires authorisation. Open the signer approval page, approve this site, then try again.');
    }
}

class Nostr_NIP46_Client {

    /**
     * Hard timeout per RPC round trip (seconds).
     */
    const RPC_TIMEOUT = 15;

    /**
     * @var Nostr_NIP46_Settings
     */
    private $settings;

    /**
     * Human-readable, secret-free trace of the most recent operation, so
     * the admin can see *where* a connection attempt failed (relay reachable?
     * request accepted? signer silent?) instead of only a generic timeout.
     *
     * @var string[]
     */
    private $diagnostics = array();

    public function __construct($settings = null) {
        $this->settings = $settings ?: Nostr_NIP46_Settings::get_instance();
    }

    /**
     * Record a diagnostic step. Callers MUST never pass the bunker secret or
     * the client private key here — only relay URLs, pubkeys, relay protocol
     * messages, counts and timings, all of which are public/non-sensitive.
     * Also mirrored into the plugin debug log when verbose logging is on.
     *
     * @param string $message
     */
    private function diag($message) {
        $this->diagnostics[] = $message;
        if (function_exists('nostr_for_wp_debug_log')) {
            nostr_for_wp_debug_log('NIP-46: ' . $message);
        }
    }

    /**
     * The secret-free trace of the most recent operation.
     *
     * @return string[]
     */
    public function get_diagnostics() {
        return $this->diagnostics;
    }

    /* ---------------------------------------------------------------------
     * High-level operations
     * ------------------------------------------------------------------ */

    /**
     * Establish (or re-verify) the session with the bunker and fetch the
     * user pubkey. Used by the "Connect and test" admin action.
     *
     * @return array{user_pubkey: string, npub: string, encryption: string}
     * @throws Exception
     */
    public function connect_and_test() {
        $this->diagnostics = array();

        $uri = $this->settings->get_bunker_uri();
        if ($uri === null) {
            throw new Exception('No bunker URI is configured.');
        }
        $bunker = Nostr_NIP46_Settings::parse_bunker_uri($uri);
        $this->diag('Bunker relays: ' . implode(', ', $bunker['relays']));
        $this->diag('Remote signer pubkey: ' . $bunker['remote_signer_pubkey']);

        // The URI secret is single-use per NIP-46, so only send `connect`
        // if this client has not completed the handshake yet. If it has,
        // get_public_key doubles as the connectivity test.
        if (!$this->settings->is_connect_secret_used()) {
            $this->diag('Sending connect request (single-use secret)');
            $result = $this->rpc('connect', array($bunker['remote_signer_pubkey'], $bunker['secret']), $bunker, true);
            // Signers answer "ack" (or echo the secret); an error would
            // have been thrown by rpc().
            unset($result);
        } else {
            $this->diag('Secret already consumed; verifying via get_public_key');
        }

        $this->diag('Requesting get_public_key');
        $user_pubkey = $this->rpc('get_public_key', array(), $bunker);
        $user_pubkey = strtolower(trim($user_pubkey));
        if (!preg_match('/^[0-9a-f]{64}$/', $user_pubkey)) {
            throw new Exception('The bunker returned an invalid public key.');
        }

        $this->settings->save_connection_state($user_pubkey, $this->settings->get_encryption());

        return array(
            'user_pubkey' => $user_pubkey,
            'npub'        => Nostr_NIP46_Crypto::npub_encode($user_pubkey),
            'encryption'  => $this->settings->get_encryption(),
        );
    }

    /**
     * Ask the bunker to sign an event with the user's key.
     *
     * @param array $event unsigned event: kind, content, tags, created_at
     * @return array fully signed, locally verified event
     * @throws Exception on failure (bunker unreachable/locked/refused)
     */
    public function sign_event($event) {
        $uri = $this->settings->get_bunker_uri();
        if ($uri === null) {
            throw new Exception('No bunker URI is configured.');
        }
        $bunker = Nostr_NIP46_Settings::parse_bunker_uri($uri);

        $unsigned = array(
            'kind'       => (int) $event['kind'],
            'content'    => (string) $event['content'],
            'tags'       => isset($event['tags']) ? $event['tags'] : array(),
            'created_at' => isset($event['created_at']) ? (int) $event['created_at'] : time(),
        );

        $result = $this->rpc('sign_event', array(wp_json_encode($unsigned)), $bunker);

        $signed = json_decode($result, true);
        if (!is_array($signed) || empty($signed['id']) || empty($signed['sig']) || empty($signed['pubkey'])) {
            throw new Exception('The bunker returned a malformed signed event.');
        }

        // Trust but verify: recompute the id and check the Schnorr
        // signature before publishing anything.
        if (Nostr_NIP46_Crypto::event_id($signed) !== $signed['id']) {
            throw new Exception('Signed event id does not match its contents.');
        }
        if (!Nostr_NIP46_Crypto::schnorr_verify($signed['id'], $signed['sig'], $signed['pubkey'])) {
            throw new Exception('Signed event has an invalid signature.');
        }
        $expected_user = $this->settings->get_user_pubkey();
        if ($expected_user && strtolower($signed['pubkey']) !== strtolower($expected_user)) {
            throw new Exception('Signed event pubkey does not match the connected bunker identity.');
        }

        return $signed;
    }

    /**
     * Ping the bunker.
     *
     * @return bool
     */
    public function ping() {
        try {
            $uri = $this->settings->get_bunker_uri();
            if ($uri === null) {
                return false;
            }
            $bunker = Nostr_NIP46_Settings::parse_bunker_uri($uri);
            $result = $this->rpc('ping', array(), $bunker);
            return strtolower(trim($result)) === 'pong';
        } catch (Exception $e) {
            return false;
        }
    }

    /* ---------------------------------------------------------------------
     * RPC transport
     * ------------------------------------------------------------------ */

    /**
     * Perform one NIP-46 RPC round trip over the bunker's relays.
     *
     * @param string $method NIP-46 method name
     * @param array $params positional string params
     * @param array $bunker parsed bunker URI
     * @param bool $allow_encryption_fallback retry with NIP-04 on timeout
     *        (used only during the initial connect, where the signer's
     *        encryption support is still unknown)
     * @return string result string
     * @throws Exception|Nostr_NIP46_Auth_Required_Exception
     */
    private function rpc($method, $params, $bunker, $allow_encryption_fallback = false) {
        $encryption = $this->settings->get_encryption();

        try {
            return $this->rpc_with_encryption($method, $params, $bunker, $encryption);
        } catch (Nostr_NIP46_Auth_Required_Exception $e) {
            throw $e;
        } catch (Exception $e) {
            // An old signer that only speaks NIP-04 cannot decrypt our
            // NIP-44 request and will simply never answer. On the initial
            // connect, retry once with NIP-04 and remember what worked.
            if ($allow_encryption_fallback && $encryption === 'nip44' && $this->is_timeout_error($e)) {
                $result = $this->rpc_with_encryption($method, $params, $bunker, 'nip04');
                $this->settings->set_encryption('nip04');
                return $result;
            }
            throw $e;
        }
    }

    /**
     * @param string $encryption 'nip44' or 'nip04'
     * @return string
     * @throws Exception
     */
    private function rpc_with_encryption($method, $params, $bunker, $encryption) {
        $keypair = $this->settings->get_client_keypair();
        $remote = $bunker['remote_signer_pubkey'];
        $this->diag(sprintf('RPC "%s" via %s (client %s)', $method, strtoupper($encryption), substr($keypair['pubkey'], 0, 8) . '…'));

        $request_id = bin2hex(random_bytes(16));
        $payload = wp_json_encode(array(
            'id'     => $request_id,
            'method' => $method,
            'params' => array_values(array_map('strval', $params)),
        ));

        if ($encryption === 'nip04') {
            $content = Nostr_NIP46_Crypto::nip04_encrypt($payload, $keypair['privkey'], $remote);
        } else {
            $conversation_key = Nostr_NIP46_Crypto::nip44_conversation_key($keypair['privkey'], $remote);
            $content = Nostr_NIP46_Crypto::nip44_encrypt($payload, $conversation_key);
        }

        // The kind 24133 envelope, signed with the client key.
        $request_event = Nostr_NIP46_Crypto::finalize_event(
            24133,
            $content,
            array(array('p', $remote)),
            $keypair['privkey']
        );

        $sockets = $this->open_relay_connections($bunker['relays'], $keypair['pubkey'], $remote);
        if (empty($sockets)) {
            throw new Exception('Could not reach any of the bunker\'s relays. Check the relay URLs and that the host allows outbound wss:// connections.');
        }

        try {
            $event_message = wp_json_encode(array('EVENT', $request_event));
            $published = 0;
            foreach ($sockets as $entry) {
                try {
                    $entry['socket']->send_text($event_message);
                    $published++;
                } catch (Exception $e) {
                    $this->diag('Failed to send request to ' . $entry['url'] . ': ' . $e->getMessage());
                }
            }
            if ($published === 0) {
                throw new Exception('Failed to publish the signing request to any relay.');
            }
            $this->diag(sprintf('Published request to %d of %d relay(s)', $published, count($sockets)));

            return $this->await_response($sockets, $request_id, $request_event['id'], $keypair, $remote, self::RPC_TIMEOUT);
        } finally {
            foreach ($sockets as $entry) {
                $entry['socket']->close();
            }
        }
    }

    /**
     * Connect to the bunker's relays and subscribe to responses addressed
     * to the client pubkey. Subscribing BEFORE publishing the request
     * guarantees the response cannot be missed.
     *
     * @return array of ['socket' => Nostr_NIP46_WebSocket, 'sub_id' => string]
     */
    private function open_relay_connections($relays, $client_pubkey, $remote_pubkey) {
        $sockets = array();

        foreach (array_slice($relays, 0, 4) as $relay_url) {
            try {
                $socket = new Nostr_NIP46_WebSocket($relay_url);
                $socket->connect(10);

                $sub_id = 'nip46-' . bin2hex(random_bytes(6));
                $filter = array(
                    'kinds'   => array(24133),
                    'authors' => array($remote_pubkey),
                    '#p'      => array($client_pubkey),
                    // Small overlap so a response racing our subscription
                    // is still delivered from relay storage.
                    'since'   => time() - 10,
                );
                $socket->send_text(wp_json_encode(array('REQ', $sub_id, $filter)));

                $sockets[] = array('socket' => $socket, 'sub_id' => $sub_id, 'url' => $relay_url);
                $this->diag('Connected to relay ' . $relay_url);
            } catch (Exception $e) {
                // Relay down — try the next one. Message is safe to log
                // (contains host/error only, never the secret).
                $this->diag('Relay unreachable ' . $relay_url . ': ' . $e->getMessage());
                error_log('Nostr NIP-46: relay connection failed: ' . $e->getMessage());
            }
        }

        return $sockets;
    }

    /**
     * Poll all connected relays for the response matching $request_id.
     *
     * @param string $request_event_id id of the published request event,
     *        used to detect relays rejecting the publish (OK ... false)
     * @return string result string
     * @throws Exception on timeout or signer-reported error
     */
    private function await_response($sockets, $request_id, $request_event_id, $keypair, $remote_pubkey, $timeout) {
        $deadline = microtime(true) + $timeout;
        $conversation_key = null;
        $rejections = array();
        $accepted = 0;
        $signer_events = 0;
        $decrypt_failures = 0;

        while (microtime(true) < $deadline) {
            foreach ($sockets as $entry) {
                $raw = $entry['socket']->receive(0.25);
                if ($raw === null) {
                    continue;
                }

                $message = json_decode($raw, true);
                if (!is_array($message) || count($message) < 2) {
                    continue;
                }

                // Track relays refusing to accept the request event, so a
                // "rejected everywhere" situation produces a clear error
                // instead of an opaque timeout.
                if ($message[0] === 'OK' && isset($message[1], $message[2]) && $message[1] === $request_event_id) {
                    if ($message[2] === false) {
                        $reason = isset($message[3]) ? (string) $message[3] : 'rejected';
                        $rejections[] = $reason;
                        $this->diag('Relay ' . $entry['url'] . ' rejected request: ' . $reason);
                        if (count($rejections) >= count($sockets)) {
                            throw new Exception('All relays rejected the signing request: ' . sanitize_text_field(implode('; ', array_unique($rejections))));
                        }
                    } else {
                        $accepted++;
                        $this->diag('Relay ' . $entry['url'] . ' accepted request');
                    }
                    continue;
                }

                // A relay demanding NIP-42 AUTH (either as an explicit AUTH
                // challenge or by closing our subscription) means the signer's
                // reply can never reach us on this relay — a common, otherwise
                // invisible cause of "timeout". Surface it.
                if ($message[0] === 'AUTH') {
                    $this->diag('Relay ' . $entry['url'] . ' requires NIP-42 AUTH (not supported); its responses cannot be received here');
                    continue;
                }
                if ($message[0] === 'CLOSED' && isset($message[1]) && $message[1] === $entry['sub_id']) {
                    $reason = isset($message[2]) ? (string) $message[2] : 'closed';
                    $this->diag('Relay ' . $entry['url'] . ' closed the subscription: ' . $reason);
                    continue;
                }
                if ($message[0] === 'NOTICE') {
                    $this->diag('Relay ' . $entry['url'] . ' notice: ' . (isset($message[1]) ? (string) $message[1] : ''));
                    continue;
                }

                if ($message[0] !== 'EVENT' || $message[1] !== $entry['sub_id'] || !isset($message[2])) {
                    continue; // EOSE — nothing to do
                }

                $event = $message[2];
                if (!is_array($event) || !isset($event['kind'], $event['pubkey'], $event['content'], $event['id'], $event['sig'])) {
                    continue;
                }

                // Only accept authentic responses from the remote signer.
                if ((int) $event['kind'] !== 24133 || strtolower($event['pubkey']) !== $remote_pubkey) {
                    continue;
                }
                $signer_events++;
                if (Nostr_NIP46_Crypto::event_id($event) !== $event['id'] ||
                    !Nostr_NIP46_Crypto::schnorr_verify($event['id'], $event['sig'], $event['pubkey'])) {
                    $this->diag('Discarded a signer event with a bad id/signature');
                    continue;
                }

                // Decrypt: the payload format identifies the scheme
                // (NIP-04 always contains "?iv="), so the signer's actual
                // encryption is detected from its response.
                try {
                    if (strpos($event['content'], '?iv=') !== false) {
                        $plaintext = Nostr_NIP46_Crypto::nip04_decrypt($event['content'], $keypair['privkey'], $remote_pubkey);
                        $this->settings->set_encryption('nip04');
                    } else {
                        if ($conversation_key === null) {
                            $conversation_key = Nostr_NIP46_Crypto::nip44_conversation_key($keypair['privkey'], $remote_pubkey);
                        }
                        $plaintext = Nostr_NIP46_Crypto::nip44_decrypt($event['content'], $conversation_key);
                        $this->settings->set_encryption('nip44');
                    }
                } catch (Exception $e) {
                    $decrypt_failures++;
                    continue; // not decryptable — not for us / corrupted
                }

                $response = json_decode($plaintext, true);
                if (!is_array($response) || !isset($response['id']) || $response['id'] !== $request_id) {
                    continue; // response to some other request
                }

                // Auth challenge: the signer wants the user to approve
                // out-of-band. Surface the URL; the admin retries after
                // approving.
                if (isset($response['result']) && $response['result'] === 'auth_url' && !empty($response['error'])) {
                    throw new Nostr_NIP46_Auth_Required_Exception(esc_url_raw($response['error']));
                }

                if (!empty($response['error'])) {
                    throw new Exception('Bunker error: ' . sanitize_text_field($response['error']));
                }

                return isset($response['result']) ? (string) $response['result'] : '';
            }
        }

        $this->diag(sprintf(
            'Timed out: %d relay(s) accepted the request, %d signer event(s) seen, %d undecryptable.',
            $accepted,
            $signer_events,
            $decrypt_failures
        ));

        // Give a targeted hint based on how far we got, rather than always
        // blaming the bunker.
        if ($signer_events > 0 && $decrypt_failures > 0) {
            $hint = ' The signer replied but the response could not be decrypted — the encryption scheme or client key may be mismatched. Try resetting the client key and re-pairing.';
        } elseif ($accepted > 0) {
            $hint = ' The relay accepted the request but no reply arrived — the bunker is likely offline, locked, or connected to different relays than the ones in the bunker URI.';
        } else {
            $hint = ' The request could not be confirmed as accepted by any relay — check that the relays in the bunker URI are online and reachable from this host.';
        }

        throw new Exception(sprintf(
            'Timed out after %d seconds waiting for the bunker to respond.%s',
            $timeout,
            $hint
        ));
    }

    /**
     * @return bool whether the exception represents a no-response timeout
     */
    private function is_timeout_error($e) {
        return strpos($e->getMessage(), 'Timed out') === 0;
    }
}
