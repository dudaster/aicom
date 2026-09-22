# AICOM ↔ AICOMBase connector (v3.17.0)

Plugin side of `~/Projects/aicombase/docs/PROTOCOL.md` (the wire contract — change both together).
Optional and inert until an admin clicks **AICOMBase → Connect**. AICOM works fully standalone; every
network call is time-bounded, off the front-end path, and backs off exponentially on failure.
(Unrelated to the older AICOM Hub channel — see `MANAGEMENT-CHANNEL.md`; both can coexist.)

## Files (`includes/`)

| File | Role |
|---|---|
| `class-base.php` | bootstrap: `aicom_minute` cron schedule, heartbeat cron, throttled admin fallback tick |
| `class-base-signer.php` | base64url, canonical string, Ed25519 sign/verify, `verify_token()` (pure) |
| `class-base-identity.php` | keypair (private key encrypted with `wp_salt('secure_auth')`), `installation_id`, rotation staging |
| `class-base-state.php` | connection state option, base URL (`AICOM_BASE_URL` constant > `aicom_base_url` option > `https://aicombase.com`) |
| `class-base-client.php` | signed HTTP client, backoff, payload scrubber |
| `class-base-connection.php` | pairing (`begin`/`poll`), disconnect, revoked wipe, UI state |
| `class-base-heartbeat.php` | heartbeat + command loop (execute, inspect, lock, pause, revoke, rotate_credentials, resync_capabilities), acks |
| `class-base-capabilities.php` | inventory from `AICOM_Tool_Registry`, hash, upload |
| `class-base-events.php` | queue (`wp_aicom_base_events`) + producers/hooks + batched flush |
| `class-base-executor.php` | token verification → local policy → ephemeral key + fresh session → tool router → `/tasks/{id}/result` |
| `class-base-wake.php` | inbound `/wake` REST route — AICOMBase-signed nudge to check in now instead of waiting for the next scheduled heartbeat; bounded long-poll for a multi-step session (§11) |
| `class-base-policy.php` | local scope cap (admin-configurable; critical scopes excluded by default) |
| `class-base-inspector.php` | privacy / security / technical / accessibility evidence |
| `class-base-rotation.php` | §8 rotation: propose (old key) → confirm (new key) → promote |
| `class-base-admin.php`, `admin/pages/base.php` | AICOMBase admin page (nonces + `manage_options`) |

Hooks added to existing classes: `aicom_audit_logged` (audit logger), `aicom_session_opened/closed` (sessions),
`aicom_lock_changed` (lock manager). DB v4.9 adds `aicom_base_events` and `aicom_base_nonces`.

## Trust model for remote execution
1. not paused / hard-locked (soft lock ⇒ read-only classes only)
2. token: Ed25519 vs **pinned** AICOMBase key, `iss` = pinned `bk_…`, `aud` = site_id, not expired, lifetime ≤ 900 s, nonce single-use (`aicom_base_nonces`, TTL 20 min), `task_target_id` matches the command
3. every scope in the token ∈ authoritative scope tree **and** ∈ local allow-list (`aicom_base_allowed_scopes`)
4. the tool's own `required_scopes` ⊆ token scopes
5. Tool Router gates still apply (lock matrix, confirm flag, session, audit). The task runs with an ephemeral internal API key limited to the token scopes, revoked + archived right after; it never leaves the process.

Refusals are reported with status `refused` and error `<code> — …` (PROTOCOL §4a);
scope violations additionally emit a `security` event `scope_rejected`.

Disconnect (admin button) first sends a best-effort signed `POST /api/v1/site/disconnect` (4 s timeout, ignored on failure), then wipes locally.
A `revoke` command issued before the current pairing is ignored client-side (belt and braces with the server-side expiry).

## Wake ping (§11)

`POST /wp-json/aicom/v1/wake` — the only inbound route AICOMBase calls (everything else is the site
calling out). Body `{token}`: a Base-signed token, same shape/verifier as an execution authorization
(`allowed_scopes:[]`, plus a `purpose:"wake"` claim `verify_token()` ignores and this endpoint checks
itself) — no new crypto, single-use via the same nonce store. On success, runs the heartbeat tick
immediately (bypasses wp-cron/backoff entirely, `$force=true`), then keeps re-checking every 3s for up
to 20s **only while the previous tick actually carried commands** — an idle site costs one extra tick,
an active multi-step session gets each new step almost immediately instead of waiting out a full wake
round-trip per step. Best-effort only, sent fire-and-forget by AICOMBase right after it queues a
command: if it never arrives (older AICOM version, request lost, site unreachable), the normal
heartbeat cadence still delivers the same work — this only shaves latency, never a hard dependency.

## Never sent / logged
WP admin password, the Ed25519 private key, AI Bearer keys. `AICOM_Base_Client::scrub()` redacts credential-shaped
values from every outgoing body (mirrors AICOMBase's `lib/protocol/secrets.ts`).

## Tests
```
docker compose exec -T wp php wp-content/plugins/aicom/tests/test-base-connector.php --allow-root
```
Offline (fake connection pinned to a closed port; real state is snapshotted/restored). Covers canonicalisation,
token verification (valid/expired/wrong aud/iss/lifetime/forged/tampered/replayed), scope escalation, event queue,
in-process execution, pause/lock, inventory, inspections. Cross-implementation check against AICOMBase's
`lib/crypto.ts` was done byte-for-byte both ways (PHP-signed request verified in Node; Node-issued token verified in PHP).

## Dev
`update_option('aicom_base_url','http://host.docker.internal:3199')` (plain http is allowed only for localhost /
host.docker.internal / `*.test|*.local`, or `AICOM_BASE_ALLOW_HTTP`). After pairing, traffic goes only to the origin pinned in state.
