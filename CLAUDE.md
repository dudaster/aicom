# AICOM Local — agent notes

## Cross-plugin channel

**`MANAGEMENT-CHANNEL.md`** in this folder is the wire contract with AICOM Hub
(`~/projects/aicomhub/aicomhub`). Both sides are implemented (May 2026) —
read that doc before changing anything signed, the bytes are shared with the
Hub plugin and any mismatch breaks pairing/sync silently.

Local-side files involved:
- `includes/class-hub-signer.php` — HMAC canonical + sign/verify (mirror of `AICOMHUB_Signer`)
- `includes/class-hub-crypto.php` — sodium encryption of the management secret at rest
- `includes/class-hub-pairing.php` — pairing-token lifecycle, pairing store, nonce dedupe
- `includes/class-hub-channel.php` — REST routes `/pair` + `/management`, cron sync push
- `admin/pages/safety.php` — "AICOM Hub Pairing" card (token generation UI)
- DB v4.6: tables `wp_aicom_hub_pairings`, `wp_aicom_hub_nonces`

## Companion plugin

AICOM Hub is the central security broker — registers sites, holds HMAC
management secrets, opens temporary AI sessions, centralizes audit. It is
**not** a content editor — keep that boundary intact when adding new
management actions here.
