# Architecture Decision Records

Decision points for nostr-for-wp, with the reasoning that produced them.
Newest last. Each entry records what was decided, why, what was rejected,
and what would cause us to revisit.

---

## ADR-1: NIP-46 crypto is assembled in-plugin on top of a pinned EC library

**Decision.** The NIP-46 remote signer client implements BIP-340 Schnorr
signing/verification, NIP-44 v2 and NIP-04 payload encryption, event-id
hashing, and bech32 in `includes/class-nip46-crypto.php`, built on:

- `simplito/elliptic-php` (pinned in composer.json) for all secp256k1 curve
  arithmetic — **not** hand-rolled;
- PHP `sodium` (bundled since PHP 7.2) for at-rest secret encryption;
- PHP `openssl` for ChaCha20 (NIP-44) and AES-256-CBC (NIP-04).

**Why.** The protocol sets the crypto floor: a NIP-46 client *must* sign its
kind 24133 RPC envelopes with BIP-340, encrypt payloads with NIP-44 (or
NIP-04), and verify signer responses. There is no lighter subset. The
alternatives were ruled out by the hosting constraints (PHP 7.4+, managed
WordPress hosting, no PECL):

- `swentel/nostr-php` — requires PHP 8.1+.
- `libsecp256k1` PECL bindings — not installable on managed hosting.
- No NIP-46 at all — the NIP-07 browser flow remains the default, but the
  entire point of this feature is signing with no browser present
  (scheduled posts).

**Trust model / blast radius.** The user's nsec never touches this code; it
stays in the bunker. Worst-case failure analysis:

- *Broken signing (nonce leak)* → leaks the plugin's **client** key. An
  attacker holding it could ask the bunker to sign arbitrary events as the
  user. Containment: the bunker logs clients and can revoke; "Reset client
  key" rotates it. This is the highest-stakes component; the implementation
  follows BIP-340's specified nonce construction (tagged hashes, CSPRNG aux
  randomness) and self-verifies every signature before returning.
- *Broken encryption* → RPC payloads are readable on relays. Payloads are
  almost entirely blog content about to be published publicly; the one real
  secret is the single-use connect token.
- *Broken verification* → spoofed bunker responses could be accepted, but
  any resulting event must still verify under the expected user pubkey
  (checked before publishing), and every relay and client downstream
  re-verifies independently. Forgery does not propagate.

**Evidence.** `tests/test-crypto.php` runs the official NIP-44 vectors
(including invalid-case vectors) and the official BIP-340 verification
vectors plus sign-roundtrips; `tests/fetch-event.php` validates event
hashing and verification against real relay events; the e2e tests achieved
live interop with `nak` (an independent implementation decrypted our
NIP-44 and vice versa).

**Known gaps.** No external audit. Pure-PHP bigint arithmetic is not
constant-time (theoretical timing side channel; hard to exploit across
relay round-trips). Roundtrip sign tests cannot detect a *biased* nonce the
way byte-exact vectors would.

**Revisit if:** the minimum PHP version moves to 8.1+ (adopt
`swentel/nostr-php`), or a libsecp256k1 binding becomes viable on managed
hosting.

## ADR-2: Vendored WebSocket client instead of a Composer dependency

**Decision.** `includes/class-nip46-websocket.php` is a small, purpose-built
wss:// client (TLS peer verification, handshake validation, ping/pong,
fragmentation, exact-length reads).

**Why.** The maintained PHP WebSocket libraries either require PHP 8+ or are
abandoned; the needed surface (client-only, text frames, short-lived
connections) is small enough that a hardened vendored implementation is a
smaller risk than an unmaintained dependency.

## ADR-3: Secrets encrypted at rest with a key derived from WP salts

**Decision.** The NIP-46 client private key and the bunker URI (whose
`secret` parameter authorises signing) are encrypted with
`sodium_crypto_secretbox` under a key derived from `AUTH_KEY`/`AUTH_SALT`
before being stored in `wp_options`.

**Why / threat model.** This is deliberate, honest obfuscation: it defeats
database-only leaks (SQL dumps, SQLi, stolen backups) because the salts
live in `wp-config.php`, outside the database. It does **not** defeat an
attacker with filesystem access — accepted, because the real user key is in
the bunker and the bunker operator can revoke this client at any time.
Consequence: rotating WordPress salts makes the stored key undecryptable;
the code surfaces this explicitly rather than silently regenerating
(see ADR-4).

## ADR-4: The client keypair is generated once and never silently rotated

**Decision.** `Nostr_NIP46_Settings::get_client_keypair()` generates the
keypair on first use and reuses it forever. If the stored key cannot be
decrypted (salts changed), it throws rather than regenerating. Rotation is
only possible through the explicit "Reset client key" admin action.

**Why.** The bunker authorises the client *pubkey*. Silent regeneration
would invisibly de-authorise the site — this exact failure mode was chased
in production (a second client pubkey in the bunker log turned out to be an
unrelated service, but only because the plugin's key was provably stable).
The client pubkey is displayed in the settings UI before first connect so
it can be authorised on the bunker ahead of time.

## ADR-5: Outbound NIP-46 publishing is queued via WP-Cron with backoff

**Decision.** Publishing never blocks or fails the WordPress publish. Posts
are queued on `transition_post_status` and processed by WP-Cron; failures
retry at 5 min / 15 min / 60 min, then hourly, giving up 24 h after first
failure (manual "Retry now" always available). Signed events are persisted
and reused so a post can never produce two different signed events
(exactly-once signing); `is_already_published()` guards re-entry.

**Why.** The bunker can be offline or locked (e.g. `nak bunker --persist`
awaiting its password after a reboot) for hours; a synchronous publish
would either block the editor or drop the Nostr publication.

## ADR-6: Inbound-created posts carry `_nostr_origin` via `meta_input`

**Decision.** When inbound sync creates a post from a Nostr event, the
content mapper passes `'meta_input' => ['_nostr_origin' => true]` to
`wp_insert_post()` — not a separate `update_post_meta()` after insert.

**Why.** `wp_insert_post()` fires `transition_post_status` *synchronously
inside the call*, and that hook is where the NIP-46 publisher decides
whether to queue the post for outbound publishing. Core writes `meta_input`
before firing the transition, so the loop guard
(`if _nostr_origin → don't queue`) sees the meta in time. A post-insert
stamp would land after the transition already fired, leaving loop
prevention dependent on a cron-timing race (the `is_already_published()`
backstop happened to win it). With an X→Nostr mirror feeding inbound sync,
the create path is the hot path — the loop had to be closed structurally.
`tests/test-inbound-guard.php` pins this: it reproduces core's insert
ordering and asserts inbound-created posts are **never queued** (verified
by mutation testing: moving the stamp back after insert fails the test).

## ADR-7: Tests live in `tests/`, excluded from the distributed plugin

**Decision.** All test harnesses, vectors, and the mock bunker live in
`tests/` (renamed from `.tools/`) and are committed. They are standalone
PHP scripts run with WordPress stubs — no PHPUnit, no WordPress install
required. Distribution excludes them: the plugin zip is built from the
`files` allowlist in `package.json`, and `.distignore` excludes `tests/`
for wp-cli dist-archive.

**Why committed:** the crypto vectors and the inbound-guard regression test
are the project's safety net against future refactors, not personal
scratch space. **Why not PHPUnit:** the suite predates any test framework
in the repo and needs to run against live relays and a mock bunker; plain
scripts with explicit stubs keep that visible and dependency-free.
