<?php
/**
 * NIP-46 Cryptography Helper
 *
 * All cryptographic primitives needed by the NIP-46 (Nostr Connect / remote
 * signer) client:
 *
 *  - secp256k1 keypair generation for the *client* keypair (the user's key
 *    never touches this server; the bunker holds it and signs user events).
 *  - BIP-340 Schnorr signing. This is required client-side ONLY to sign the
 *    kind 24133 RPC envelope events with the client keypair — relays refuse
 *    unsigned events. User-facing events (kind 1 / 30023) are always signed
 *    by the remote bunker.
 *  - secp256k1 ECDH shared secrets (x coordinate) for NIP-04 and NIP-44.
 *  - NIP-44 v2 payload encryption (ChaCha20 + HMAC-SHA256), the modern
 *    encryption used by current signers (nak, nsec.app, Amber).
 *  - NIP-04 encryption (AES-256-CBC) as a fallback for older signers.
 *  - NIP-01 event id hashing and bech32/npub encoding for display.
 *
 * secp256k1 point math comes from simplito/elliptic-php (pinned in
 * composer.json). It is a pure-PHP, MIT-licensed port of the widely used
 * JS "elliptic" library, needs no PHP extension beyond gmp OR bcmath, and
 * therefore runs on typical managed WordPress hosting. Symmetric primitives
 * use OpenSSL (ChaCha20, AES-CBC) and hash_hmac/hash_hkdf from PHP core.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Nostr_NIP46_Crypto {

    /**
     * Shared secp256k1 context (expensive to build, reuse it)
     *
     * @var \Elliptic\EC|null
     */
    private static $ec = null;

    /**
     * Get the secp256k1 EC context, loading the Composer autoloader lazily
     * so the dependency is only required when NIP-46 features are used.
     *
     * @return \Elliptic\EC
     * @throws Exception if the vendor autoloader is missing
     */
    private static function ec() {
        if (self::$ec === null) {
            if (!class_exists('\Elliptic\EC')) {
                $autoload = NOSTR_FOR_WP_PLUGIN_DIR . 'vendor/autoload.php';
                if (!file_exists($autoload)) {
                    throw new Exception('NIP-46 support requires the Composer dependencies. Run "composer install" in the plugin directory.');
                }
                require_once $autoload;
            }
            self::$ec = new \Elliptic\EC('secp256k1');
        }
        return self::$ec;
    }

    /**
     * Curve order n as hex (secp256k1)
     */
    const CURVE_N = 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141';

    /* ---------------------------------------------------------------------
     * Key management
     * ------------------------------------------------------------------ */

    /**
     * Generate a fresh secp256k1 keypair.
     *
     * @return array{privkey: string, pubkey: string} 64-char hex strings
     *         (pubkey is the x-only BIP-340 form used everywhere in Nostr)
     */
    public static function generate_keypair() {
        $ec = self::ec();
        $n = new \BN\BN(self::CURVE_N, 16);
        do {
            $priv_hex = bin2hex(random_bytes(32));
            $priv = new \BN\BN($priv_hex, 16);
        } while ($priv->isZero() || $priv->cmp($n) >= 0);

        return array(
            'privkey' => str_pad($priv_hex, 64, '0', STR_PAD_LEFT),
            'pubkey'  => self::pubkey_from_privkey($priv_hex),
        );
    }

    /**
     * Derive the x-only public key from a private key.
     *
     * @param string $priv_hex 64-char hex private key
     * @return string 64-char hex x-only public key
     */
    public static function pubkey_from_privkey($priv_hex) {
        $ec = self::ec();
        $key = $ec->keyFromPrivate($priv_hex, 'hex');
        $pub = $key->getPublic();
        return str_pad($pub->getX()->toString(16), 64, '0', STR_PAD_LEFT);
    }

    /* ---------------------------------------------------------------------
     * ECDH shared secret (basis for NIP-04 and NIP-44)
     * ------------------------------------------------------------------ */

    /**
     * Compute the ECDH shared secret between our private key and a peer's
     * x-only public key. Per NIP-44 (and de-facto NIP-04 practice) the
     * x-only pubkey is lifted to the point with EVEN y ("02" prefix) and the
     * shared secret is the x coordinate of priv * pubkey — unhashed.
     *
     * @param string $priv_hex our private key (hex)
     * @param string $peer_pub_hex peer x-only public key (hex)
     * @return string 32 raw bytes (x coordinate, big-endian, zero-padded)
     */
    public static function ecdh_shared_x($priv_hex, $peer_pub_hex) {
        $ec = self::ec();
        if (!is_string($peer_pub_hex) || !preg_match('/^[0-9a-f]{64}$/i', $peer_pub_hex)) {
            throw new Exception('ECDH: invalid public key format');
        }
        // x must be a valid field element (< p); pointFromX would silently
        // reduce it mod p otherwise.
        if ((new \BN\BN($peer_pub_hex, 16))->cmp($ec->curve->p) >= 0) {
            throw new Exception('ECDH: public key x out of range');
        }
        $point = $ec->curve->pointFromX($peer_pub_hex, false); // even y
        $priv = new \BN\BN($priv_hex, 16);
        $shared = $point->mul($priv);
        $x_hex = str_pad($shared->getX()->toString(16), 64, '0', STR_PAD_LEFT);
        return hex2bin($x_hex);
    }

    /* ---------------------------------------------------------------------
     * NIP-44 v2 (https://github.com/nostr-protocol/nips/blob/master/44.md)
     *
     * conversation_key = HKDF-extract(salt="nip44-v2", ikm=shared_x)
     * per-message: HKDF-expand(conversation_key, info=nonce32, 76 bytes)
     *              -> chacha_key(32) | chacha_nonce(12) | hmac_key(32)
     * payload = base64( 0x02 || nonce32 || chacha20(padded) || hmac32 )
     * hmac is computed over nonce || ciphertext (nonce acts as AAD).
     * ------------------------------------------------------------------ */

    /**
     * Derive the NIP-44 conversation key for a peer.
     *
     * @return string 32 raw bytes
     */
    public static function nip44_conversation_key($priv_hex, $peer_pub_hex) {
        $shared_x = self::ecdh_shared_x($priv_hex, $peer_pub_hex);
        // HKDF-extract: PRK = HMAC-SHA256(key=salt, msg=IKM)
        return hash_hmac('sha256', $shared_x, 'nip44-v2', true);
    }

    /**
     * Encrypt a plaintext with NIP-44 v2.
     *
     * @param string $plaintext UTF-8 plaintext (1..65535 bytes)
     * @param string $conversation_key 32 raw bytes
     * @param string|null $nonce optional 32-byte nonce (tests only)
     * @return string base64 payload
     */
    public static function nip44_encrypt($plaintext, $conversation_key, $nonce = null) {
        $len = strlen($plaintext);
        if ($len < 1 || $len > 65535) {
            throw new Exception('NIP-44: invalid plaintext length');
        }
        if ($nonce === null) {
            $nonce = random_bytes(32);
        }

        list($chacha_key, $chacha_nonce, $hmac_key) = self::nip44_message_keys($conversation_key, $nonce);

        $padded = pack('n', $len) . $plaintext . str_repeat("\0", self::nip44_calc_padded_len($len) - $len);
        $ciphertext = self::chacha20($chacha_key, $chacha_nonce, $padded);
        $mac = hash_hmac('sha256', $nonce . $ciphertext, $hmac_key, true);

        return base64_encode("\x02" . $nonce . $ciphertext . $mac);
    }

    /**
     * Decrypt a NIP-44 v2 payload.
     *
     * @param string $payload base64 payload
     * @param string $conversation_key 32 raw bytes
     * @return string plaintext
     */
    public static function nip44_decrypt($payload, $conversation_key) {
        if ($payload === '' || $payload[0] === '#') {
            throw new Exception('NIP-44: unsupported payload version');
        }
        $plen = strlen($payload);
        if ($plen < 132 || $plen > 87472) {
            throw new Exception('NIP-44: invalid payload size');
        }
        $data = base64_decode($payload, true);
        if ($data === false) {
            throw new Exception('NIP-44: invalid base64');
        }
        $dlen = strlen($data);
        if ($dlen < 99 || $dlen > 65603 || ord($data[0]) !== 2) {
            throw new Exception('NIP-44: invalid payload');
        }

        $nonce = substr($data, 1, 32);
        $ciphertext = substr($data, 33, $dlen - 65);
        $mac = substr($data, $dlen - 32);

        list($chacha_key, $chacha_nonce, $hmac_key) = self::nip44_message_keys($conversation_key, $nonce);

        $expected_mac = hash_hmac('sha256', $nonce . $ciphertext, $hmac_key, true);
        if (!hash_equals($expected_mac, $mac)) {
            throw new Exception('NIP-44: invalid MAC');
        }

        $padded = self::chacha20($chacha_key, $chacha_nonce, $ciphertext);
        $unpadded_len = unpack('n', substr($padded, 0, 2))[1];
        if ($unpadded_len < 1 || $unpadded_len > 65535 ||
            strlen($padded) !== 2 + self::nip44_calc_padded_len($unpadded_len)) {
            throw new Exception('NIP-44: invalid padding');
        }
        $plaintext = substr($padded, 2, $unpadded_len);
        // Trailing padding must be all zero bytes.
        if (rtrim(substr($padded, 2 + $unpadded_len), "\0") !== '') {
            throw new Exception('NIP-44: invalid padding');
        }
        return $plaintext;
    }

    /**
     * HKDF-expand(conversation_key, info=nonce, L=76) split into the three
     * per-message keys.
     *
     * @return array{0: string, 1: string, 2: string} chacha_key, chacha_nonce, hmac_key
     */
    private static function nip44_message_keys($conversation_key, $nonce) {
        if (strlen($conversation_key) !== 32 || strlen($nonce) !== 32) {
            throw new Exception('NIP-44: invalid key material');
        }
        // PHP's hash_hkdf() always performs extract(salt, key) before
        // expanding, but the conversation key is already a PRK; NIP-44
        // requires HKDF-expand only, so do the expand manually.
        $okm = self::hkdf_expand_sha256($conversation_key, $nonce, 76);
        return array(
            substr($okm, 0, 32),
            substr($okm, 32, 12),
            substr($okm, 44, 32),
        );
    }

    /**
     * RFC 5869 HKDF-Expand with SHA-256 (expand only, PRK given).
     */
    private static function hkdf_expand_sha256($prk, $info, $length) {
        $okm = '';
        $t = '';
        $i = 0;
        while (strlen($okm) < $length) {
            $i++;
            $t = hash_hmac('sha256', $t . $info . chr($i), $prk, true);
            $okm .= $t;
        }
        return substr($okm, 0, $length);
    }

    /**
     * NIP-44 padded length calculation.
     */
    private static function nip44_calc_padded_len($unpadded_len) {
        if ($unpadded_len <= 32) {
            return 32;
        }
        $next_power = pow(2, floor(log($unpadded_len - 1, 2)) + 1);
        $chunk = ($next_power <= 256) ? 32 : intval($next_power / 8);
        return intval($chunk * (floor(($unpadded_len - 1) / $chunk) + 1));
    }

    /**
     * ChaCha20 (RFC 8439, 12-byte nonce, counter 0) via OpenSSL.
     * OpenSSL's "chacha20" EVP cipher takes a 16-byte IV: a 32-bit
     * little-endian block counter followed by the 96-bit nonce.
     */
    private static function chacha20($key, $nonce12, $data) {
        $iv = "\x00\x00\x00\x00" . $nonce12;
        $out = openssl_encrypt($data, 'chacha20', $key, OPENSSL_RAW_DATA, $iv);
        if ($out === false) {
            throw new Exception('ChaCha20 unavailable: OpenSSL cipher failed');
        }
        return $out;
    }

    /* ---------------------------------------------------------------------
     * NIP-04 (legacy fallback: AES-256-CBC, key = raw ECDH x coordinate)
     * ------------------------------------------------------------------ */

    /**
     * @return string "base64(ciphertext)?iv=base64(iv)"
     */
    public static function nip04_encrypt($plaintext, $priv_hex, $peer_pub_hex) {
        $key = self::ecdh_shared_x($priv_hex, $peer_pub_hex);
        $iv = random_bytes(16);
        $ct = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($ct === false) {
            throw new Exception('NIP-04: encryption failed');
        }
        return base64_encode($ct) . '?iv=' . base64_encode($iv);
    }

    public static function nip04_decrypt($payload, $priv_hex, $peer_pub_hex) {
        $parts = explode('?iv=', $payload);
        if (count($parts) !== 2) {
            throw new Exception('NIP-04: malformed payload');
        }
        $ct = base64_decode($parts[0], true);
        $iv = base64_decode($parts[1], true);
        if ($ct === false || $iv === false || strlen($iv) !== 16) {
            throw new Exception('NIP-04: malformed payload');
        }
        $key = self::ecdh_shared_x($priv_hex, $peer_pub_hex);
        $pt = openssl_decrypt($ct, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($pt === false) {
            throw new Exception('NIP-04: decryption failed');
        }
        return $pt;
    }

    /* ---------------------------------------------------------------------
     * BIP-340 Schnorr signatures (sign + verify)
     * ------------------------------------------------------------------ */

    /**
     * BIP-340 tagged hash: sha256(sha256(tag) || sha256(tag) || msg)
     */
    private static function tagged_hash($tag, $msg) {
        $tag_hash = hash('sha256', $tag, true);
        return hash('sha256', $tag_hash . $tag_hash . $msg, true);
    }

    /**
     * Sign a 32-byte message hash with BIP-340 Schnorr.
     *
     * @param string $msg_hash_hex 64-char hex message (usually an event id)
     * @param string $priv_hex 64-char hex private key
     * @return string 128-char hex signature
     */
    public static function schnorr_sign($msg_hash_hex, $priv_hex) {
        $ec = self::ec();
        $n = new \BN\BN(self::CURVE_N, 16);
        $msg = hex2bin($msg_hash_hex);

        $d = new \BN\BN($priv_hex, 16);
        if ($d->isZero() || $d->cmp($n) >= 0) {
            throw new Exception('Schnorr: invalid private key');
        }

        $P = $ec->g->mul($d);
        if ($P->getY()->isOdd()) {
            $d = $n->sub($d);
        }
        $px = hex2bin(str_pad($P->getX()->toString(16), 64, '0', STR_PAD_LEFT));
        $d_bytes = hex2bin(str_pad($d->toString(16), 64, '0', STR_PAD_LEFT));

        $aux = random_bytes(32);
        $t = $d_bytes ^ self::tagged_hash('BIP0340/aux', $aux);
        $rand = self::tagged_hash('BIP0340/nonce', $t . $px . $msg);

        $k = (new \BN\BN(bin2hex($rand), 16))->umod($n);
        if ($k->isZero()) {
            throw new Exception('Schnorr: bad nonce');
        }

        $R = $ec->g->mul($k);
        if ($R->getY()->isOdd()) {
            $k = $n->sub($k);
        }
        $rx = hex2bin(str_pad($R->getX()->toString(16), 64, '0', STR_PAD_LEFT));

        $e = (new \BN\BN(bin2hex(self::tagged_hash('BIP0340/challenge', $rx . $px . $msg)), 16))->umod($n);
        $s = $k->add($e->mul($d)->umod($n))->umod($n);

        $sig = bin2hex($rx) . str_pad($s->toString(16), 64, '0', STR_PAD_LEFT);

        if (!self::schnorr_verify($msg_hash_hex, $sig, bin2hex($px))) {
            throw new Exception('Schnorr: signature self-check failed');
        }
        return $sig;
    }

    /**
     * Verify a BIP-340 Schnorr signature.
     *
     * @param string $msg_hash_hex 64-char hex message
     * @param string $sig_hex 128-char hex signature
     * @param string $pub_hex 64-char hex x-only public key
     * @return bool
     */
    public static function schnorr_verify($msg_hash_hex, $sig_hex, $pub_hex) {
        try {
            $ec = self::ec();
            $n = new \BN\BN(self::CURVE_N, 16);
            $p = $ec->curve->p;

            if (strlen($sig_hex) !== 128 || strlen($pub_hex) !== 64) {
                return false;
            }

            $P = $ec->curve->pointFromX($pub_hex, false); // even y
            $r = new \BN\BN(substr($sig_hex, 0, 64), 16);
            $s = new \BN\BN(substr($sig_hex, 64), 16);
            if ($r->cmp($p) >= 0 || $s->cmp($n) >= 0) {
                return false;
            }

            $msg = hex2bin($msg_hash_hex);
            $rx = hex2bin(substr($sig_hex, 0, 64));
            $px = hex2bin($pub_hex);
            $e = (new \BN\BN(bin2hex(self::tagged_hash('BIP0340/challenge', $rx . $px . $msg)), 16))->umod($n);

            // R = s*G + (n - e)*P
            $R = $ec->g->mulAdd($s, $P, $n->sub($e));
            if ($R->isInfinity() || $R->getY()->isOdd()) {
                return false;
            }
            return $R->getX()->cmp($r) === 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /* ---------------------------------------------------------------------
     * NIP-01 event helpers
     * ------------------------------------------------------------------ */

    /**
     * Compute the NIP-01 event id: sha256 of the canonical JSON array
     * [0, pubkey, created_at, kind, tags, content].
     *
     * Note: json_encode with these flags matches the NIP-01 serialization
     * for the payloads this client produces (base64/hex/JSON strings).
     *
     * @param array $event with pubkey, created_at, kind, tags, content
     * @return string 64-char hex id
     */
    public static function event_id($event) {
        $serialized = json_encode(array(
            0,
            $event['pubkey'],
            (int) $event['created_at'],
            (int) $event['kind'],
            $event['tags'],
            $event['content'],
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return hash('sha256', $serialized);
    }

    /**
     * Build, id and sign a complete Nostr event with the given private key.
     *
     * @param int $kind
     * @param string $content
     * @param array $tags
     * @param string $priv_hex
     * @return array signed event
     */
    public static function finalize_event($kind, $content, $tags, $priv_hex) {
        $event = array(
            'kind'       => $kind,
            'content'    => $content,
            'tags'       => $tags,
            'created_at' => time(),
            'pubkey'     => self::pubkey_from_privkey($priv_hex),
        );
        $event['id'] = self::event_id($event);
        $event['sig'] = self::schnorr_sign($event['id'], $priv_hex);
        return $event;
    }

    /* ---------------------------------------------------------------------
     * Bech32 (NIP-19) encoding — for displaying npub in the admin UI
     * ------------------------------------------------------------------ */

    const BECH32_CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

    /**
     * Encode a hex pubkey as an npub bech32 string.
     *
     * @param string $pub_hex 64-char hex public key
     * @return string npub1...
     */
    public static function npub_encode($pub_hex) {
        $data = array_values(unpack('C*', hex2bin($pub_hex)));
        $five_bit = self::convert_bits($data, 8, 5, true);
        return self::bech32_encode('npub', $five_bit);
    }

    private static function bech32_encode($hrp, $data) {
        $checksum = self::bech32_create_checksum($hrp, $data);
        $combined = array_merge($data, $checksum);
        $out = $hrp . '1';
        foreach ($combined as $d) {
            $out .= self::BECH32_CHARSET[$d];
        }
        return $out;
    }

    private static function bech32_create_checksum($hrp, $data) {
        $values = array_merge(self::bech32_hrp_expand($hrp), $data, array(0, 0, 0, 0, 0, 0));
        $polymod = self::bech32_polymod($values) ^ 1;
        $checksum = array();
        for ($i = 0; $i < 6; $i++) {
            $checksum[] = ($polymod >> (5 * (5 - $i))) & 31;
        }
        return $checksum;
    }

    private static function bech32_hrp_expand($hrp) {
        $result = array();
        $len = strlen($hrp);
        for ($i = 0; $i < $len; $i++) {
            $result[] = ord($hrp[$i]) >> 5;
        }
        $result[] = 0;
        for ($i = 0; $i < $len; $i++) {
            $result[] = ord($hrp[$i]) & 31;
        }
        return $result;
    }

    private static function bech32_polymod($values) {
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

    private static function convert_bits($data, $from_bits, $to_bits, $pad = true) {
        $acc = 0;
        $bits = 0;
        $ret = array();
        $maxv = (1 << $to_bits) - 1;
        foreach ($data as $value) {
            $acc = (($acc << $from_bits) | $value) & 0xffffffff;
            $bits += $from_bits;
            while ($bits >= $to_bits) {
                $bits -= $to_bits;
                $ret[] = ($acc >> $bits) & $maxv;
            }
        }
        if ($pad && $bits > 0) {
            $ret[] = ($acc << ($to_bits - $bits)) & $maxv;
        }
        return $ret;
    }
}
