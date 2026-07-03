<?php
/**
 * NIP-46 Settings & Secret Storage
 *
 * Owns everything the remote signer feature persists:
 *
 *  - the signing method toggle ('nip07' default, or 'nip46')
 *  - the plugin's NIP-46 *client* keypair (generated server-side once and
 *    reused so the bunker's authorisation of this client persists)
 *  - the bunker:// connection URI
 *  - non-secret connection state (remote signer pubkey, user pubkey,
 *    negotiated encryption, connect timestamp)
 *
 * SECRETS AT REST: the client private key and the full bunker URI (whose
 * `secret` query parameter authorises signing) are encrypted with
 * sodium crypto_secretbox before being written to wp_options. The
 * secretbox key is derived from the WordPress AUTH_KEY/AUTH_SALT constants
 * in wp-config.php, which live outside the database. This is deliberate,
 * honest obfuscation: it protects against database-only leaks (SQL dumps,
 * SQL injection, stolen backups) but NOT against an attacker with full
 * filesystem access to the host, who could read wp-config.php and decrypt.
 * That is the accepted threat model for this feature — the real user key
 * stays in the bunker, and a bunker operator can revoke this client at
 * any time.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Nostr_NIP46_Settings {

    const OPTION_KEY = 'nostr_for_wp_nip46';

    /**
     * Single instance
     */
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
    }

    /* ---------------------------------------------------------------------
     * Signing method
     * ------------------------------------------------------------------ */

    /**
     * @return string 'nip07' (default) or 'nip46'
     */
    public function get_signing_method() {
        $options = get_option('nostr_for_wp_options', array());
        $method = isset($options['signing_method']) ? $options['signing_method'] : 'nip07';
        return ($method === 'nip46') ? 'nip46' : 'nip07';
    }

    /**
     * @param string $method 'nip07' or 'nip46'
     */
    public function set_signing_method($method) {
        $options = get_option('nostr_for_wp_options', array());
        $options['signing_method'] = ($method === 'nip46') ? 'nip46' : 'nip07';
        update_option('nostr_for_wp_options', $options);
    }

    /**
     * Whether bunker mode is selected AND fully configured.
     *
     * @return bool
     */
    public function is_bunker_active() {
        return $this->get_signing_method() === 'nip46'
            && $this->get_bunker_uri() !== null
            && $this->get_user_pubkey() !== null;
    }

    /* ---------------------------------------------------------------------
     * Encryption at rest
     * ------------------------------------------------------------------ */

    /**
     * Whether the host can encrypt secrets (sodium is bundled with
     * PHP >= 7.2 so this should virtually always be true).
     *
     * @return bool
     */
    public function can_encrypt() {
        return function_exists('sodium_crypto_secretbox') && defined('AUTH_KEY') && AUTH_KEY !== '';
    }

    /**
     * Derive the at-rest key from WordPress salts. See the class docblock
     * for the threat model.
     *
     * @return string 32 raw bytes
     */
    private function derive_key() {
        if (!$this->can_encrypt()) {
            throw new Exception('Secret storage requires the PHP sodium extension and AUTH_KEY defined in wp-config.php.');
        }
        $salt = defined('AUTH_SALT') ? AUTH_SALT : 'nostr-for-wp';
        return sodium_crypto_generichash(AUTH_KEY . '|' . $salt . '|nostr-for-wp-nip46', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    /**
     * @param string $plaintext
     * @return string base64(nonce || box)
     */
    private function encrypt($plaintext) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = sodium_crypto_secretbox($plaintext, $nonce, $this->derive_key());
        return base64_encode($nonce . $box);
    }

    /**
     * @param string $stored base64(nonce || box)
     * @return string|null plaintext, or null if missing/undecryptable
     */
    private function decrypt($stored) {
        if (!is_string($stored) || $stored === '') {
            return null;
        }
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        try {
            $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $box = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = sodium_crypto_secretbox_open($box, $nonce, $this->derive_key());
            return ($plain === false) ? null : $plain;
        } catch (Exception $e) {
            return null;
        }
    }

    /* ---------------------------------------------------------------------
     * Option plumbing
     * ------------------------------------------------------------------ */

    private function get_data() {
        $data = get_option(self::OPTION_KEY, array());
        return is_array($data) ? $data : array();
    }

    private function update_data($data) {
        // autoload=false: only publish paths need this option.
        update_option(self::OPTION_KEY, $data, false);
    }

    /* ---------------------------------------------------------------------
     * Client keypair
     * ------------------------------------------------------------------ */

    /**
     * Get the persistent NIP-46 client keypair, generating it on first use.
     * The keypair is intentionally reused across sessions: the bunker
     * authorises the client *pubkey*, so regenerating would force the user
     * to re-authorise in the bunker. Never regenerated silently — see
     * reset_client_keypair().
     *
     * @return array{privkey: string, pubkey: string}
     * @throws Exception if secret storage is unavailable
     */
    public function get_client_keypair() {
        $data = $this->get_data();

        if (!empty($data['client_privkey'])) {
            $priv = $this->decrypt($data['client_privkey']);
            if ($priv !== null && preg_match('/^[0-9a-f]{64}$/', $priv)) {
                return array(
                    'privkey' => $priv,
                    'pubkey'  => isset($data['client_pubkey']) ? $data['client_pubkey'] : Nostr_NIP46_Crypto::pubkey_from_privkey($priv),
                );
            }
            // Stored key exists but cannot be decrypted (salts changed?).
            // Do NOT silently regenerate; surface the problem instead.
            throw new Exception('Stored NIP-46 client key cannot be decrypted. If your WordPress salts changed, use "Reset client key" and re-authorise the bunker.');
        }

        $keypair = Nostr_NIP46_Crypto::generate_keypair();
        $data['client_privkey'] = $this->encrypt($keypair['privkey']);
        $data['client_pubkey'] = $keypair['pubkey'];
        $this->update_data($data);

        return $keypair;
    }

    /**
     * The client pubkey without touching private material (may be null
     * before first use).
     *
     * @return string|null
     */
    public function get_client_pubkey() {
        $data = $this->get_data();
        return !empty($data['client_pubkey']) ? $data['client_pubkey'] : null;
    }

    /**
     * Explicitly discard the client keypair and all connection state.
     * The bunker will need to re-authorise the newly generated client on
     * the next connect.
     */
    public function reset_client_keypair() {
        $data = $this->get_data();
        unset(
            $data['client_privkey'],
            $data['client_pubkey'],
            $data['bunker_uri'],
            $data['remote_signer_pubkey'],
            $data['bunker_relays'],
            $data['user_pubkey'],
            $data['encryption'],
            $data['connected_at'],
            $data['connect_secret_used']
        );
        $this->update_data($data);
    }

    /* ---------------------------------------------------------------------
     * Bunker URI
     * ------------------------------------------------------------------ */

    /**
     * Parse and validate a bunker:// connection URI.
     *
     * Format (NIP-46):
     *   bunker://<remote-signer-pubkey>?relay=<wss-url>&relay=...&secret=<token>
     *
     * @param string $uri
     * @return array{remote_signer_pubkey: string, relays: array, secret: string}
     * @throws Exception with an admin-friendly message (never echoes the secret)
     */
    public static function parse_bunker_uri($uri) {
        $uri = trim((string) $uri);
        if (stripos($uri, 'bunker://') !== 0) {
            throw new Exception('Not a bunker:// URI. Paste the connection string printed by your remote signer.');
        }

        $rest = substr($uri, strlen('bunker://'));
        $query = '';
        $qpos = strpos($rest, '?');
        if ($qpos !== false) {
            $query = substr($rest, $qpos + 1);
            $rest = substr($rest, 0, $qpos);
        }

        $pubkey = strtolower(trim($rest, '/'));
        if (!preg_match('/^[0-9a-f]{64}$/', $pubkey)) {
            throw new Exception('The bunker URI does not contain a valid 64-character hex signer pubkey.');
        }

        // parse_str() collapses repeated keys, but NIP-46 allows multiple
        // relay= params, so parse pairs manually.
        $relays = array();
        $secret = '';
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $kv = explode('=', $pair, 2);
            $k = urldecode($kv[0]);
            $val = isset($kv[1]) ? urldecode($kv[1]) : '';
            if ($k === 'relay' && $val !== '') {
                if (!preg_match('#^wss?://#i', $val) || !filter_var($val, FILTER_VALIDATE_URL)) {
                    throw new Exception('The bunker URI contains an invalid relay URL.');
                }
                $relays[] = $val;
            } elseif ($k === 'secret') {
                $secret = $val;
            }
        }

        if (empty($relays)) {
            throw new Exception('The bunker URI must include at least one relay parameter.');
        }

        return array(
            'remote_signer_pubkey' => $pubkey,
            'relays'               => array_values(array_unique($relays)),
            'secret'               => $secret,
        );
    }

    /**
     * Store the bunker URI (encrypted — its secret authorises signing) plus
     * the non-secret parts in the clear for display and connection reuse.
     *
     * @param string $uri validated bunker:// URI
     */
    public function save_bunker_uri($uri) {
        $parsed = self::parse_bunker_uri($uri);
        $data = $this->get_data();
        $data['bunker_uri'] = $this->encrypt($uri);
        $data['remote_signer_pubkey'] = $parsed['remote_signer_pubkey'];
        $data['bunker_relays'] = $parsed['relays'];
        // New URI means new secret; clear stale connection state.
        unset($data['user_pubkey'], $data['encryption'], $data['connected_at'], $data['connect_secret_used']);
        $this->update_data($data);
    }

    /**
     * @return string|null decrypted bunker URI
     */
    public function get_bunker_uri() {
        $data = $this->get_data();
        return isset($data['bunker_uri']) ? $this->decrypt($data['bunker_uri']) : null;
    }

    /**
     * Non-secret bunker info for UI display (never includes the secret).
     *
     * @return array{remote_signer_pubkey: ?string, relays: array}
     */
    public function get_bunker_display_info() {
        $data = $this->get_data();
        return array(
            'remote_signer_pubkey' => isset($data['remote_signer_pubkey']) ? $data['remote_signer_pubkey'] : null,
            'relays'               => isset($data['bunker_relays']) && is_array($data['bunker_relays']) ? $data['bunker_relays'] : array(),
        );
    }

    /**
     * Remove the stored bunker URI and connection state (keeps the client
     * keypair so reconnecting to the same bunker needs no re-authorisation).
     */
    public function clear_bunker() {
        $data = $this->get_data();
        unset(
            $data['bunker_uri'],
            $data['remote_signer_pubkey'],
            $data['bunker_relays'],
            $data['user_pubkey'],
            $data['encryption'],
            $data['connected_at'],
            $data['connect_secret_used']
        );
        $this->update_data($data);
    }

    /* ---------------------------------------------------------------------
     * Connection state
     * ------------------------------------------------------------------ */

    /**
     * Record a successful connect + get_public_key handshake.
     *
     * @param string $user_pubkey hex user pubkey returned by the bunker
     * @param string $encryption 'nip44' or 'nip04'
     */
    public function save_connection_state($user_pubkey, $encryption) {
        $data = $this->get_data();
        $data['user_pubkey'] = $user_pubkey;
        $data['encryption'] = ($encryption === 'nip04') ? 'nip04' : 'nip44';
        $data['connected_at'] = time();
        $data['connect_secret_used'] = true;
        $this->update_data($data);
    }

    /**
     * @return string|null hex user pubkey the bunker signs with
     */
    public function get_user_pubkey() {
        $data = $this->get_data();
        return !empty($data['user_pubkey']) ? $data['user_pubkey'] : null;
    }

    /**
     * @return string 'nip44' (default) or 'nip04'
     */
    public function get_encryption() {
        $data = $this->get_data();
        return (isset($data['encryption']) && $data['encryption'] === 'nip04') ? 'nip04' : 'nip44';
    }

    /**
     * Persist the encryption scheme detected from the signer's responses.
     *
     * @param string $encryption 'nip44' or 'nip04'
     */
    public function set_encryption($encryption) {
        $data = $this->get_data();
        $data['encryption'] = ($encryption === 'nip04') ? 'nip04' : 'nip44';
        $this->update_data($data);
    }

    /**
     * Whether the connect handshake already consumed the URI secret. Used
     * to skip re-sending `connect` (NIP-46: the secret is single-use;
     * signers SHOULD ignore re-connects with an old secret).
     *
     * @return bool
     */
    public function is_connect_secret_used() {
        $data = $this->get_data();
        return !empty($data['connect_secret_used']);
    }

    /**
     * @return int|null unix timestamp of the last successful handshake
     */
    public function get_connected_at() {
        $data = $this->get_data();
        return isset($data['connected_at']) ? intval($data['connected_at']) : null;
    }
}
