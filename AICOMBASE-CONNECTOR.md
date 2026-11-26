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

## Sessions (3.20.0)

Nothing AICOMBase sends runs outside a session AICOMBase opened: `open_session` (heartbeat command) creates an ordinary
AICOM session tagged `source='aicombase'` with `base_session_id` = AICOMBase's id (`AICOM_Sessions::open_remote`), shown in
AICOM → Activity → Sessions with an "AICOMBase" badge and a Close button. `AICOM_Base_Executor` refuses an `execute` whose
authorization `session_id` has no open session (`NO_ACTIVE_SESSION`); otherwise it attaches that execution's ephemeral key
to the session (`attach_key`), so every task of one AICOMBase session runs — and is backed up — in the same AICOM session,
which stays open after the task. `close_session` / `restore_session` close it / replay its backups newest first
(`AICOM_Base_Heartbeat::cmd_restore_session`, reported as `session.restored`). Closing in WP admin, the 2 h stale rule and a
restore all report back through the usual session events, so AICOMBase stops using the session.

## Undo per call (change journal, unreleased)

Every write tool call (not reads, sessions, cache purges, or bridged abilities, which return their own `undo`) runs inside
`AICOM_Change_Journal`: it follows the call's SQL writes (`query` filter) and keeps how each row, meta key, option and
term link was before the call and after it, as one `aicom_backups` row (`target_type = journal`). It does the same for
rows of the plugin tables in `ROW_TABLES` (WPML's `icl_translations`, AICOM's Skills and their revisions; a new row is
known by its `insert_id`) and for files, up to 4 MB a call: a file a tool deletes, read just before WordPress deletes
it, or one `files.upload` is about to write. A file changed since the call is left as it is. The call's result then
carries `undo: {via: "restore_session", complete, changes, backup_id?, reasons?, outside?}` — `complete: false` with a
reason when it wrote to a table Undo doesn't cover, sent an email, deleted a file, or touched a user it can't bring back;
`outside` lists hosts it called (informational). `restore_session` replays journals newest first and skips the router's
snapshots of the same call (`supersedes`); anything changed again after the AI is left as it is and listed in
`session.restored` as `kept: [...]`. A dry run that writes anyway is put back at once and answered `DRY_RUN_UNSUPPORTED`.
Tools listed reversible in `/site/capabilities` are `AICOM_Change_Journal::VERIFIED`, each proven by
`tests/test-undo-journal.php` (run, restore, database identical).

## Extension points (§12, unreleased)

All optional, both ways: an AICOMBase that says nothing gets exactly what it got before.
- **`features`.** Every heartbeat sends `AICOM_Base_Heartbeat::FEATURES` (`undo.journal`,
  `dry_run.refuses_unsupported`, `session.restored.kept`, `lists.page_cap`, `notices`, `site.notice`). Add a name
  for each new capability and never remove one a version still supports. Every answer's `features` is kept as
  `base_features` (malformed names dropped, at most 50). `AICOM_Base_State::base_supports()` reads it, and an answer
  without `features` means none.
- **`notices`** (`AICOM_Notices`). A handler may return `notices: [{level, text, items?, code?}]`. The router cleans
  them to the protocol caps (≤ 10; text ≤ 500; items ≤ 50 × ≤ 200; code ≤ 60; no tags) and appends them to the MCP
  text content, so every AI client reads them. When AICOMBase lists `notices`, the executor moves them from the
  result to the result body's `notices`; otherwise they stay in the result like any other field. Events carry them
  the same way, and only when AICOMBase lists `notices`. Today:
  - `builder.elementor` (warn): `wp.posts.update` changes `post_content` of a page built with Elementor, also on a
    dry run. The tool's description tells agents the same.
  - `restore.failed` (error) on `session.restored`: what Undo could not put back.
- **`site.notice`** (`AICOM_Base_Events::site_notice()`). Sent only when AICOMBase lists `site.notice`, and each
  `code` at most once an hour, AICOMBase's own rule. Texts are translated with the site's language. Today:

  | Code | Level | When |
  |---|---|---|
  | `dependency.deactivated.<module>` | warn | a plugin AICOM's tools depend on is deactivated: how many tools went |
  | `session.closed_stale` | info | the 2 h rule closed AICOMBase sessions (linked to the session when there is one) |
  | `backups.retention` | info | the daily cleanup deleted snapshots by age |
  | `backups.size_limit` | warn | it deleted snapshots to stay under the size limit |
  | `undo.journal_failed` | warn | a call's change journal couldn't be saved |

  No WordPress content goes into these texts, only names, counts and tool names.

## Wake ping (§11)

`POST /wp-json/aicom/v1/wake` — the only inbound route AICOMBase calls (everything else is the site
calling out). Body `{token}`: a Base-signed token, same shape/verifier as an execution authorization
(`allowed_scopes:[]`, plus a `purpose:"wake"` claim `verify_token()` ignores and this endpoint checks
itself) — no new crypto, single-use via the same nonce store. On success, answers at once
(`{ok:true, accepted:true}`) and does the work after the response is out (`shutdown` →
`AICOM_Base::detach_from_client()`: `ignore_user_abort(true)` + `litespeed_finish_request()` /
`fastcgi_finish_request()`) — LiteSpeed ends a PHP process once its caller hangs up, which once left an
execute step claimed and never run. The work: the heartbeat tick runs
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
