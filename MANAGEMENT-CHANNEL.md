# AICOM Local ↔ Hub — Management Channel

> **Status:** Implemented on both sides — May 2026.
> AICOM Local v3.7.0 + DB schema v4.6 · AICOM Hub v0.1.0.
> Audience: anyone touching the channel on either plugin. Read first before changing wire format — every byte is shared between two codebases.

## What this is

The two-way authenticated channel between **AICOM Local** (this plugin) and **AICOM Hub** (`~/projects/aicomhub/aicomhub`). Hub manages Local; Local pushes status + audit events back. Spec source: PRD §5.1, §16.1, §16.2, §16.3.

## What's wired up

| Concern | Local file | Hub file |
|---|---|---|
| HMAC canonical + sign/verify | `includes/class-hub-signer.php` | `includes/class-signer.php` |
| Reversible secret encryption (sodium + `wp_salt('secure_auth')`) | `includes/class-hub-crypto.php` | `includes/class-credentials.php` |
| Pairing store + nonce replay store | `includes/class-hub-pairing.php` | `includes/class-credentials.php` |
| Outbound client (sign + POST) | *(sync push only)* `class-hub-channel.php::push_one()` | `includes/class-client.php` |
| Inbound REST routes | `class-hub-channel.php::register_routes()` | `includes/class-rest.php` |
| Pairing-token admin UI | `admin/pages/safety.php` (Hub Pairing card) | `admin/pages/sites.php` (Pair-a-site form) |
| DB schema | `aicom_hub_pairings`, `aicom_hub_nonces` (`includes/class-db.php` v4.6) | `aicomhub_credentials` (`includes/class-db.php`) |
| Cron jobs | `aicom_hub_sync` hourly, `aicom_hub_nonce_gc` daily | — |

The end-to-end round-trip (pair → signed status.get → tampered request rejected → disallowed action 403 → sync push) is verified — see "Sanity check" below.

## Endpoints — AICOM Local

### `POST /wp-json/aicom/v1/pair`  (unsigned, one-time)

**Request:**
```json
{
  "pairing_token": "<plain token from Local admin>",
  "hub_id": "hub_<hex>",
  "hub_url": "https://hub.example.com"
}
```

Local validates the token (sha256 hash via transient, ≤10 min lifetime, single-use), then generates:
- `management_key_id` = `mgmt_` + 16 hex chars
- `management_secret` = 32 random bytes hex-encoded (64 chars)

Stores `(hub_id, hub_url, management_key_id, management_secret_encrypted)` in `wp_aicom_hub_pairings` — secret encrypted via `sodium_crypto_secretbox`, key derived from `wp_salt('secure_auth')`. Re-pairing the same `hub_id` replaces the row.

**Response 200 (one-time only):**
```json
{
  "ok": true,
  "management_key_id": "mgmt_xxxxxxxxxxxxxxxx",
  "management_secret": "<64 hex chars>",
  "aicom_version": "3.7.0",
  "site_url": "<canonical site URL>"
}
```

**Errors:** `400 missing_fields`, `400 bad_payload`, `401 invalid_token`. Every attempt logs to `AICOM_Audit_Logger`.

### `POST /wp-json/aicom/v1/management`  (signed)

**Headers:**
```
X-AICOM-Hub-Id:      hub_<hex>
X-AICOM-Key-Id:      mgmt_<hex>
X-AICOM-Timestamp:   <unix seconds>
X-AICOM-Nonce:       <32 hex chars>
X-AICOM-Request-Id:  <16 hex chars>
X-AICOM-Signature:   <hex hmac-sha256>
Content-Type:        application/json
```

**Canonical string — byte-exact, `\n`-joined, no trailing newline:**
```
POST
/wp-json/aicom/v1/management
<timestamp>
<nonce>
<hex sha256 of raw body>
```

**Signature:** `hash_hmac('sha256', $canonical, $management_secret_plaintext)`.

**Validation order (first failure wins):**
1. All four signed headers present → else `401 unsigned`
2. `key_id` resolves to a stored pairing → else `401 unknown_key`
3. `|now - ts| ≤ 300s` and HMAC `hash_equals` → else `401 bad_signature`
4. `(key_id, nonce)` not seen before — `INSERT IGNORE` into `aicom_hub_nonces` → else `401 replay`
5. `action` ∈ `AICOM_Hub_Channel::ALLOWED_ACTIONS` (Local Policy Cap, PRD §16.3) → else `403 scope_forbidden`

**Body:** `{ "action": "status.get" | "lock.set" | …, "params": { … } }`

**Actions currently wired** (extend `AICOM_Hub_Channel::dispatch()` and add to `ALLOWED_ACTIONS` to grow):
- `status.get` — returns `{aicom_version, wp_version, lock_status, active_keys, open_sessions}`
- `lock.set` — `params: {soft, hard}` — toggles `AICOM_Lock_Manager` soft/hard lock

Anything outside the allowlist is refused with `403 scope_forbidden` **even with a valid signature** — defense in depth.

## Local → Hub sync push

`AICOM_Hub_Channel::cron_push_all()` runs hourly (`aicom_hub_sync` hook) and:

1. Iterates every paired Hub in `aicom_hub_pairings`.
2. Picks up to 200 rows from `aicom_logs` newer than the per-Hub cursor option `aicom_hub_last_sync_{pairing_id}` (default = 1h ago).
3. Maps each log row to `{event_type, risk_level, message, metadata}` — success/error rows split by `risk_level`.
4. POSTs to `<hub_url>/wp-json/aicomhub/v1/sync` signed with the same key/secret pair (canonical path is `/wp-json/aicomhub/v1/sync`).
5. Advances the cursor **only on HTTP 200** — failed pushes retry the same window. Per the Clautron memo, this is batch-only; we never send one request per event.

Hub verifies via `AICOMHUB_REST::sync()`, rejects with `401 unsigned|unknown_key|bad_signature|not_paired` otherwise.

## Hard "do not" list

- **Never** send the AI user's Bearer key to the Hub. Hub's `aicomhub_keys` table stores `last4` + scopes only.
- **Never** return `management_secret` after the pairing response. It's one-time output.
- **Never** sign without the sha256(body) step, or with a different separator. The other side rejects.
- **Never** drop nonce replay protection. Skew window (300 s) without dedupe = 300 s replay window.
- **Never** add an action to `ALLOWED_ACTIONS` without considering Local Policy Cap implications — destructive actions should never be Hub-callable by default.

## Sanity check (passes today)

```
[1] Generate pairing token on Local        → token: 62f09fec…
[2] Hub calls /pair with the token         → key_id: mgmt_1d04…, aicom_version: 3.7.0
[3] Hub registers site + stores credential → site_id assigned, secret encrypted at rest
[4] Hub calls /management status.get       → 200, lock_status returned
[5] Replay same nonce                       → refused
[6] Tampered signature                      → 401 bad_signature
[7] Disallowed action (users.delete)        → 403 scope_forbidden (Local Policy Cap)
[8] Sync push Local → Hub                   → events landed on Hub
```

## Pointers for next changes

- Adding a new management action → `class-hub-channel.php`: add to `ALLOWED_ACTIONS` + a case in `dispatch()`. Mirror it as an `AICOMHUB_Client::call()` use on the Hub.
- Making the policy cap per-site configurable → replace the `ALLOWED_ACTIONS` constant with a `get_option( 'aicom_hub_allowed_actions', […] )` read.
- Changing the canonical layout → coordinated update needed in **both** signers; bump a protocol version header and refuse mismatches at validation step 1.
