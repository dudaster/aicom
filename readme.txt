=== AICOM - AI Commander ===
Contributors: dudaster
Tags: mcp, ai, automation, rest-api, ai-agent
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 3.12.0
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Use your AI subscription to manage WordPress: create Elementor pages, update content, automate tasks, and stay fully in control.

== Description ==

**AICOM - AI Commander** connects your WordPress site to any AI agent via MCP (Model Context Protocol) or OpenAPI. Use your existing AI subscription — Claude Code, OpenAI Codex, ChatGPT Custom GPTs, Copilot Studio, Dify, n8n, OpenClaw, Celine, Goose, or any MCP-compatible client — to manage content, build pages, run audits, and automate repetitive tasks, all without leaving your AI interface.

No more copy-pasting between your AI assistant and the WordPress dashboard. Describe what you want, and your agent does it — safely, with a full record of every action.

= Content Management =

Create, update, and publish posts, pages, and custom post types directly from your AI agent. Build and duplicate Elementor pages, manage menus, upload media, update taxonomies, and handle bulk SEO fields including **Yoast SEO meta, titles, and social previews** — all in a single conversation. What used to take hours of dashboard work can be delegated to your AI in minutes.

= Safety You Control =

AICOM puts you in charge at every level:

* **Scope-based API keys** — each key grants access only to the operations you explicitly allow. One key for read-only content review, another for full publishing access.
* **Soft Lock / Hard Lock** — freeze all write operations (Soft) or block everything except public tools (Hard) with one click from the admin bar or Safety page.
* **Working Hours Schedule** — automatically apply Soft or Hard Lock outside your configured working hours and days. Agents can't make changes at night or on weekends unless you explicitly override it.
* **Dry-run mode** — test any operation before it runs. See exactly what would change without touching live data.
* **Confirm flag** — destructive operations require an explicit `"confirm": true` parameter. Accidental deletions are prevented by design.

= Backup & Restore =

AICOM automatically snapshots a post, term, or Elementor page/template right before your agent updates, trashes, deletes, or edits it — no extra step required, even if the agent never calls a backup tool itself. If something goes wrong, restore the exact previous version in one call, or undo an entire session at once: the Snapshots page lists every session that touched content, with a one-click **Restore session** button that reverts every post, term, and Elementor page it modified — including Elementor Theme Builder display conditions. Backups are stored in the database and can be cleaned up automatically based on age or total size.

= Full Audit Trail =

Every request is logged: timestamp, remote IP, API key label, tool used, parameters, result, and response time. Logs are grouped into **named sessions** — when an agent opens a session, all its actions are recorded together so you can review, replay, or undo an entire workflow at once. The Audit Logs page includes a session activity chart and a direct Restore button for each session.

= Built for Reliable Automation =

AICOM speaks strict MCP JSON-RPC: every successful call returns a `content` field for full client compatibility, protocol errors use standard integer codes, and errors from a tool that actually ran are reported as `isError` so your agent can see and react to them. Pass an `idempotency_key` on any write call and a retried request (dropped connection, flaky client) returns the original result instead of repeating the action — no duplicate posts from an accidental double-send. Responses are hardened against corrupted output, so your agent always gets valid JSON back.

= Accessibility Audits — New in v3.2.0 =

AICOM now includes a dedicated **Accessibility module** so your AI agent can audit and fix WCAG issues across your entire site — no external tools or services required:

* **Site report** — instantly see how many images are missing alt text across your entire media library, with a ranked preview of the top offenders and a percentage score.
* **Post audit** — scan any post or page for heading hierarchy errors (missing H1, skipped heading levels), images without alt text, and links with non-descriptive anchor text ("click here", "read more"). Each issue is rated by severity and the post receives an overall accessibility score from 0 to 100.
* **Fix in place** — set alt text on any media library image in one call. Pass an empty string to correctly mark it as decorative (`aria-hidden`). Full dry-run support so you can preview changes before saving.
* **Screenshot before & after** — combine AICOM's audit tools with your AI agent's browser capabilities to capture a visual record of the page before and after remediation.

A typical AI-driven accessibility workflow: run the site report, get the list of problem images, let the agent analyse each image visually and write descriptive alt text, apply the fixes — then run the audit again to confirm the score improved. All in one session, with a full audit trail.

= Supported Modules =

* **WordPress Core** — posts, pages, custom post types, terms, meta, options, menus, plugins
* **Media** — upload, update, delete media; direct file system access
* **Users & Roles** — create, update, manage users and role assignments
* **Backup** — automatic snapshots for posts, terms, and Elementor pages/templates before every write; session-wide restore
* **Sessions** — named audit sessions with grouped action history
* **Accessibility** — site-wide alt text audit, post-level WCAG check, alt text fixes
* **WooCommerce** *(optional)* — products, categories, settings
* **Elementor** *(optional)* — page creation from template, widget inspection, theme builder conditions
* **Polylang** *(optional)* — translations, language assignment, string management, and one-call bilingual post pair creation
* **Yoast SEO** *(optional)* — read and write SEO titles, meta descriptions, Open Graph and Twitter card fields; bulk audit across all posts in one session
* **Clautron** *(optional)* — blueprint management, capability catalog, event analytics
* **ECS** *(optional)* — Ele Custom Skin color schemes, font schemes, custom looks

= Who is this for? =

* **Content teams** using Claude, ChatGPT, or any AI assistant who want it to publish and update WordPress content directly
* **Agencies** managing multiple client sites and wanting AI-assisted workflows with a full paper trail
* **Developers** building AI-powered WordPress tools or automation pipelines
* **Accessibility specialists** who want to audit and remediate WCAG issues at scale with AI assistance
* **Claude Code users** — point AICOM as an MCP server from your terminal and control WordPress alongside your code
* **OpenAI Codex users** — connect Codex to your site via AICOM's MCP endpoint and let it manage content as part of your dev workflow
* **ChatGPT, Copilot Studio, Dify, n8n users** — import the OpenAPI schema URL into any OpenAPI-compatible client; all tools are discovered automatically with Bearer auth
* **OpenClaw / Celine / Goose users** — native MCP connector, works out of the box

= How it works =

AICOM exposes a secure HTTP endpoint on your WordPress site. Your AI agent sends structured MCP requests, AICOM authenticates the request, checks scopes and lock state, executes the operation, logs it, and returns a structured response.

`AI Agent → AICOM Endpoint → WordPress`

= API Key Scopes =

Each API key is granted specific scopes — you control exactly what each AI agent can and cannot do:

`read.wp`, `write.wp.posts`, `manage.taxonomies`, `manage.meta`, `manage.wordpress.settings`, `manage.media`, `manage.files`, `manage.users`, `manage.plugins`, `manage.backups`, `manage.a11y`, `manage.woocommerce.products`, `manage.woocommerce.settings`, `manage.elementor`, `manage.polylang`, `manage.yoast`, `manage.clautron`

= Endpoint =

**REST API:**
`POST /wp-json/aicom/v1/mcp`

**Fallback** (no mod_rewrite required):
`POST /?aicom=1`

**Health check:**
`GET /?aicom=1`

= Authentication =

`Authorization: Bearer aicom_XXXXXXXX_<secret>`

or:

`X-API-Key: aicom_XXXXXXXX_<secret>`

= MCP Request Example =

`{"jsonrpc":"2.0","method":"tools/call","params":{"name":"wp.posts.list","arguments":{"post_type":"post","posts_per_page":10}},"id":1}`

== Installation ==

1. Upload the `aicom` folder to `/wp-content/plugins/` or install directly from **Plugins → Add New** by searching for "AICOM"
2. Activate the plugin via **Plugins → Installed Plugins**
3. Go to **AICOM → API Keys** and click **Generate New Key**
4. Give the key a label (e.g. "OpenClaw agent") and select the scopes you want to grant
5. Copy the key immediately — it will not be shown again
6. Point your AI agent or MCP client to `https://yoursite.com/wp-json/aicom/v1/mcp`
7. Pass the key as `Authorization: Bearer <your-key>` in every request

**Apache note:** If the Authorization header is stripped by your server, add this line to `.htaccess`:

`SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`

**Safety tip:** Start with **Soft Lock** enabled to limit the agent to read-only operations, then unlock once you're confident in the integration.

== Frequently Asked Questions ==

= Does this plugin make my site publicly accessible to anyone? =

No. Every request must include a valid API key. Keys are bcrypt-hashed in the database and scoped — each key only has access to the specific operations you explicitly grant it. Without a valid key, the endpoint returns 401 Unauthorized.

= Does it work without mod_rewrite or pretty permalinks? =

Yes. The fallback endpoint `/?aicom=1` works on any server configuration, with or without pretty permalinks or Apache mod_rewrite.

= Is it compatible with WooCommerce, Elementor, and Polylang? =

Yes. Each plugin's tools are loaded automatically only if the corresponding plugin is active. If WooCommerce is not installed, no WooCommerce tools appear in the tool list or audit log.

= Can I restrict an AI agent to read-only access? =

Yes, in two ways: (1) assign only `read.wp` scopes to the API key, or (2) enable **Soft Lock** or **Hard Lock** mode from the Safety page — this blocks write and destructive operations site-wide regardless of key scopes.

= What is the difference between Soft Lock and Hard Lock? =

**Soft Lock** permits `public`, `discovery` and `read` class tools only — agents can browse and read content but cannot write, delete or change settings. **Hard Lock** permits only `public` tools (like `server.status`) — the site is effectively frozen from an AI perspective. Hard Lock overrides Soft Lock.

= Can I test operations before they actually run? =

Yes. Send `"dry_run": true` in your request parameters. The operation will be validated and simulated but no data will be changed. The audit log will record it as a dry run.

= Does my agent need to remember to back things up before making changes? =

No. AICOM automatically snapshots a post, term, or Elementor page before your agent updates, trashes, deletes, or edits it — this happens at the plugin level regardless of what the agent does or forgets to do. You can restore any individual snapshot, or undo an entire session (every post, term, and Elementor page it touched) in one click from the Snapshots page.

= What happens if my AI agent sends the same write request twice? =

Pass an `"idempotency_key"` in the request arguments. If AICOM sees the same key again — from a dropped connection, a retry, or a duplicate send — it returns the original result instead of repeating the action, so you never end up with two copies of the same post from one intended change.

= Does it log what AI agents do? =

Yes. Every request is logged to the audit log with timestamp, remote IP, API key label, tool name, parameters, result summary, and response duration. The log is accessible from **AICOM → Audit Logs** and can be filtered by date, key, or tool name.

= What is MCP (Model Context Protocol)? =

MCP is an open standard created by Anthropic for connecting AI models to external tools and data sources. AICOM implements the MCP standard so any MCP-compatible AI client — Claude, OpenClaw, and others — can communicate with your WordPress site natively without custom integrations.

= Is this plugin free? =

Yes, completely free and open source under the GPL-2.0-or-later license.

= Can I restrict which IP addresses can use an API key? =

Yes. Each API key has an optional IP allowlist. If set, requests from any other IP will be rejected even if the key is valid.

== Screenshots ==

1. **Dashboard** — Real-time server status, MCP endpoint URL, lock state indicator, today's request count broken down by result, and list of active modules.
2. **API Keys** — Generate keys with granular scopes (read, write, manage per module), optional IP allowlist, expiry date, and scope presets. View all keys with last-used date and status.
3. **Audit Logs** — Full request history grouped into named sessions, with a per-day activity chart colour-coded by tool class. Filter by date, key, tool, or session. One-click session restore.
4. **Safety Controls** — Soft Lock, Hard Lock, and Working Hours Schedule. Set which days and hours agents are allowed to operate; outside those hours the site locks automatically. Includes the full Lock Permission Matrix.
5. **Modules** — Overview cards for all active modules (WordPress Core, Media, Users, Backup, Sessions, Accessibility, WooCommerce, Elementor, Polylang, Yoast, Clautron) with status and registered tools.
6. **Backups** — Overview of all post, term, and Elementor page snapshots created automatically before AI agent edits: total count, storage used, activity by period, and auto-cleanup status. The Sessions with Snapshots panel lists every session with a one-click **Restore session** button; the Backup Snapshots tab lists every individual snapshot with its session, tool class, and a one-click restore button.

== Changelog ==

= 3.12.0 =

* Fixed a bug where `tools/list` invoked as an ordinary tool call (rather than the standard `tools/list` request) was missing the required `content` field, causing strict MCP clients to reject it.
* `tools/list` now only shows the tools an API key can actually call — a key without a given scope no longer sees (and can't waste a round-trip on) tools it would immediately get denied for. Every tool list is scoped to the calling key's real permissions.
* Errors rejected before a tool runs now include a `retryable` hint, so an agent can tell whether hammering the same call again could ever help, instead of assuming the server is unreachable.
* New tool `pll.create_bilingual_pair`: create a translated draft, set its language, link it to the source post, optionally assign a category and featured image, and verify every step — all in one call. Works from an existing source post, or from scratch (pass `source_language` + `source_post_title` to create both language versions in a single call, with no pre-existing post needed).
* `dry_run` and `idempotency_key` are now documented directly in every eligible tool's schema, instead of being accepted but invisible — some strict MCP clients validate outgoing calls against the schema and would silently refuse to send an undocumented parameter.
* `wp.posts.create` now reports `requested`/`persisted`/`verified` so you can see exactly what WordPress actually stored versus what was asked for (e.g. a duplicate slug getting a "-2" suffix). Extended the same pattern to `wp.posts.update`, `wp.terms.create`, `wp.terms.update`, `wp.meta.set`, and `wp.meta.set_many`.

= 3.11.1 =

* Fixed a critical `tools/list` compatibility bug: a tool with no parameters (e.g. `session.close`, `pll.languages.list`) serialized its empty schema as `[]` instead of `{}`. Strict MCP clients (Pydantic-based, including Hermes) reject the *entire* tool list over this single type mismatch, breaking tool discovery — and every tool call — completely. Reported and diagnosed with a full client-side validation log from a user; thank you.
* Removed a non-standard `class` field that was present on every tool in `tools/list` since v3.8.8. It was never part of the MCP Tool schema and could trip the same kind of strict-client rejection; the same information is now exposed the spec-compliant way via `annotations` (`readOnlyHint`/`destructiveHint`), only included when the negotiated protocol version supports it.
* Added automated regression tests that check the raw `tools/list` response body for both issues, across both supported protocol versions, so a future change can't silently reintroduce either one.

= 3.11.0 =

* `wp.posts.update` and `wp.terms.update` no longer report `updated: true` when no actual field was supplied — they now return `updated: false` with a clear warning, and `changed_fields` listing exactly what changed when something did.
* Parameter aliasing extended (`slug`, `media_id`) and now reported back in the response as `_aliases_applied`, so you can see exactly what the plugin auto-corrected instead of it happening silently.
* Read-after-write verification added to the write paths where a silent mismatch is realistic: assigning/removing post terms, attaching media/setting a featured image, and every Polylang write tool (set language, link/unlink translations, string translations). Each now re-reads the actual state and reports `requested`/`persisted`/`verified`, with a warning if they don't match — catches cases like WordPress re-assigning a default category, or Polylang silently rejecting a language change.
* `session.open` now reports `available_scopes` and `missing_scopes` for the key, scoped to the site's active modules, so an agent can tell upfront whether it has what it needs for a task like a translation workflow — before it starts, not after hitting a scope error partway through.
* Polylang translation tools now include a full JSON example and the canonical 6-step workflow order (create draft, set language, link translations, assign translated category, set featured image, verify) directly in their tool descriptions.

= 3.10.0 =

* Automatic pre-write snapshots: posts, terms, and Elementor pages/templates now get a safety snapshot before every update, trash, delete, or field edit — automatically, even if the agent never calls a backup tool itself.
* New "Sessions with Snapshots" panel on the Snapshots page — restore every post, term, and Elementor page touched during a session back to its pre-session state with one click. Now also restores Elementor Theme Builder display conditions correctly.
* MCP responses now always include a `content` field on successful tool calls, for full compatibility with strict MCP clients.
* Clearer error handling: errors rejected before a tool runs (auth, scope, session, lock) use a standard JSON-RPC integer error code; errors from a tool that actually ran and failed are now reported as `isError` with a description, so agents can see and react to them directly.
* New opt-in `idempotency_key` argument on write/destructive tools — pass the same key on a retried call and AICOM returns the original result instead of repeating the action (e.g. no duplicate post from a dropped connection and retry).
* MCP protocol version is now negotiated from the client's request instead of hardcoded, so responses match what each client actually supports.
* Hardened against corrupted responses: a stray notice from another active plugin, or an unexpected server error, can no longer produce a broken response — agents always get back valid JSON.
* Tested up to WordPress 7.1.

= 3.9.1 =

* Renamed "Connect AI Agents" admin page/menu to "Manage API Keys" for clarity.
* Sidenav footer now shows a red "New version available" notice with a link to the Plugins page when an update is available.
* Fixed `manage.polylang.settings` being unusable: it was required by `pll.term.set_language` and `pll.string.set` but was never registered as a grantable scope, so no API key — not even Full Admin — could hold it.
* Relabeled Polylang scopes for clarity: `manage.polylang` is now "Manage Polylang Post translations" and `manage.polylang.settings` is "Manage Polylang Term & String translations", each with a tooltip explaining exactly what it does and does not allow.

= 3.9.0 =

* Fixed OpenAPI schema compatibility with ChatGPT Custom GPT Actions: upgraded spec from 3.0.0 to 3.1.1, serialized empty parameter objects as `{}` instead of `[]`, and added `components.schemas` required by strict validators. Thanks allanantoni for the detailed report and fix.

= 3.8.9 =

* JSON repair for malformed payloads from local AI models (literal control characters, trailing commas, UTF-8 BOM).
* `rest_pre_dispatch` intercept bypasses WordPress JSON validation so weak models can connect without "rest_invalid_json" errors.
* New `session.status` tool — check whether a session is open before calling `session.open`, avoiding SESSION_ALREADY_OPEN errors.
* `TOOL_NOT_FOUND` now includes fuzzy name suggestions ("Did you mean: wp.posts.create?") to guide models that hallucinate tool names.
* Educational parameter warnings: unknown parameter names are flagged with the correct name and a usage example; aliases (`status`, `content`, `post_id`) are resolved automatically with a hint.
* Truncated API key detection with character count in the error message.
* Split `manage.polylang` scope: post language assignment and translation linking remain under `manage.polylang`; string translations and term language/linking now require the new `manage.polylang.settings` scope.

= 3.8.8 =

* Full MCP `inputSchema` now returned in `tools/list` — each tool includes parameter types, descriptions, and required flags so models can call tools correctly without prior knowledge.
* Compact tool list (name + class only) available via shorthand `tools` or `list_tools` method for small-context models.
* Detect `method:"tools/wp.posts.create"` pattern and return a corrected JSON-RPC example.
* Explicit error when `tools/call` is sent without a `name` field.

= 3.8.7 =

* Server-side discoverability for weak local AI models: new `aicom.recipes` tool returns step-by-step task recipes filtered to the key's actual permissions and active modules.
* Compact `initialize` instructions — three exact steps with copy-paste JSON-RPC format.
* `session.status` registered as a discovery tool (no session required, no scope required).

= 3.8.6 =

* New read-only scopes for Polylang, WooCommerce, and Elementor — grant an agent the ability to inspect data from these integrations without giving it write access.
* The Connect AI Agents form shows a small "included automatically" note under each read scope when its matching manage scope is ticked, so the implication is visible.

= 3.8.5 =

* Fixed audit log table missing `session_id` column on fresh installs — every request was failing the INSERT silently, leaving the Activity tab empty. The fix repairs existing installs automatically on update.
* New AICOM-Only Mode (Safety page, default off) — blocks Application Passwords, XML-RPC, and unsigned REST writes so AI agents can only modify the site through AICOM. Recommended at onboarding.

= 3.8.4 =

* MCP standard handshake — `initialize`, `notifications/initialized`, and `ping` now return spec-compliant responses, so strict MCP clients can connect.
* Onboarding wizard pre-selects a new General option marked Recommended — works with any MCP-aware agent without per-client config.
* Clearer error message when a write is attempted without an active session.

= 3.8.3 =

* New General tab in the Key Created modal — paste-ready snippet that explains the endpoint, fallback, and the browser-header workaround for 401s.
* New Discard key button next to Done — revoke a freshly-created key in one click if you change your mind.
* Translation polish across all 7 bundled locales.

= 3.8.2 =

* Translations bundled for Dutch, German, French, Spanish, Portuguese (Portugal & Brazil), and Romanian — 628 strings each, informal address throughout.

= 3.8.1 =

* Fixed an onboarding CSS rule that cropped the WordPress admin under the top toolbar on every page.

= 3.8.0 =

**New**

* First-run onboarding wizard.
* Interactive Help page with per-client setup snippets.
* WordPress dashboard widget.
* Per-key Working Hours.
* Task-oriented quick start cards on the dashboard.

**Polish**

* Renamed pages, client picker + Test Connection in the Key Created modal, classic pagination on Activity logs, glossary tooltips, and many smaller refinements throughout.

= 3.7.0 =

**Brand refresh**

* New visual identity: lowercase **a**i**com** wordmark, ink + cream + coral palette.
* New plugin icon (animated SVG) and updated WordPress.org banner.
* Refined admin UI: warmer surfaces, accent only where it matters, monochrome menu icon that follows your admin color scheme.

**Compatibility**

* Tested with WordPress 7.0.

= 3.6.1 =

**Security**

* Fixed security issues.

= 3.6.0 =

**Security**

* Fixed security issues.

= 3.5.0 =
* New: JSON-RPC 2.0 batch support — send multiple tool calls in a single request as a top-level JSON array; each item is dispatched independently and responses are returned as an array. Notifications (items without id) are processed but excluded from the response per spec.
* New: Skills detail view — click Details in the action menu to see a full readable breakdown of a skill (input schema, steps, rules, tags, permissions) with an Export JSON button.
* New: Skills import — paste a skill JSON definition directly in the admin UI to create a draft skill without calling the MCP API.
* New: Skills archive & restore — archived skills are now visible in the list with Restore and Delete actions.
* Improvement: Audit Logs — merged the separate Logs and Filters tabs into a single Logs tab; the filter form is always visible at the top.
* Improvement: API Keys — preset action icons (rename ✏, duplicate ❐, delete ✕) are now grouped horizontally with CSS tooltips on hover.

= 3.4.0 =
* New: Skills — define reusable AI procedures (steps, rules, input schema, permissions) that agents can discover, run, and propose updates to. Includes 11 MCP tools: skills.list, skills.get, skills.match, skills.compare, skills.run, skills.create, skills.activate, skills.update, skills.archive, skills.delete, skills.import, skills.suggest_from_session, skills.propose_update.
* New: Skills admin UI — four-tab panel (Skills, Suggested, Proposals, History) with kebab action menus, search, and type/status filters.
* New: Three new API key scopes: manage.skills, read.skills, learn.skills.
* New: session.close response includes suggest_skill: true when skill suggestions are enabled, prompting the agent to offer saving the workflow as a reusable skill.

= 3.3.0 =
* New: OpenAPI schema endpoint — GET /wp-json/aicom/v1/schema generates a live OpenAPI 3.0 spec from all registered tools.
* New: Individual tool REST endpoints — POST /wp-json/aicom/v1/tools/{tool.name} compatible with any OpenAPI client.
* Works with ChatGPT Custom GPT Actions, Microsoft Copilot Studio, Dify, Flowise, n8n, Make.com, LangChain, Semantic Kernel, and any client that supports OpenAPI 3.0 + Bearer auth. Point at the schema URL, add your AICOM key, and all tools are discovered automatically.

= 3.2.0 =
* New: Accessibility module — a11y.images_missing_alt, a11y.audit_post, a11y.set_image_alt, a11y.site_report tools for AI-driven WCAG remediation.

= 3.1.0 =
* New: Working Hours Schedule — automatically apply Soft or Hard Lock outside configured working hours and days.
* The manual lock always takes precedence; the schedule only adds additional restrictions.

= 3.0.0 =
* New: Resource Boundaries UI — configure post type, taxonomy, meta key, WP option, file path, and language restrictions per API key directly from the edit/create form.
* New: Preset Rename — rename any custom preset in-place via a prompt dialog.
* New: Preset Duplicate — clone any custom preset; the copy appears instantly in the preset grid.

= 2.9.2 =
* Fix: Toolbar lock buttons (Unlock / Soft Lock / Hard Lock) now work on frontend pages, not only in wp-admin.

= 2.9.1 =
* Improvement: Session description now shown inside the expanded session card in Audit Logs (hidden when collapsed).
* Improvement: tools/list response now includes an instructions field telling the agent whether a session is active, and prompting it to call session.open with both name and description before making changes.
* Improvement: session.open tool description updated to explicitly request a meaningful name and description from the agent.

= 2.9.0 =
* New: Backups page redesigned into 3 tabs — Dashboard (total count, storage used, activity by period, auto-cleanup status), Cleanup Settings, and Backup Snapshots.
* New: Backup Snapshots table now shows Class badge (colour-coded by tool class) and Session column with a direct link to the corresponding session in Audit Logs, including scroll-to + highlight on arrival.
* New: Toolbar lock controls — Unlock / Soft Lock / Hard Lock buttons in the AICOM Keys dropdown; toolbar badge turns red on Hard Lock and amber on Soft Lock.
* New: Stacked bar chart in Audit Logs Sessions tab — each bar segment is colour-coded by tool class (read/write/destructive/admin_sensitive); legend shown below graph.
* New: Clicking a graph bar navigates to that day's sessions via server-side filtering (log_date).
* New: Class column added to session log tables in Audit Logs.
* New: Session filter added to Audit Logs Filters tab.
* Improvement: Cleanup Settings form redesigned — each field on its own row with description on the right; fields separated by dividers.
* Improvement: Tab navigation on Backups and Audit Logs pages now uses consistent aicom-tab-bar / aicom-tab-btn styles matching API Keys page.
* Fix: Graph bars no longer show tool classes from orphaned logs (sessions that were deleted); uses INNER JOIN to exclude them.
* Fix: DB v4.4 — added tool_class column to wp_aicom_logs with backfill migration.

= 2.8.0 =
* New: Named sessions — agents must call session.open(name: "...") before making any changes; all write operations blocked until a session is opened; sessions auto-close after 2h of inactivity.
* New: Session restore — Audit Logs → Sessions tab shows all sessions with a 30-day activity graph; click Restore to undo all backups from a session in reverse chronological order.
* New: Backup cleanup — set a max age (days) and/or max size (MB) for automatic backup pruning; runs daily via cron.
* Improvement: Audit Logs split into Logs / Sessions / Filters tabs for easier navigation.
* Fix: session_id now correctly populated in backup rows.

= 2.7.0 =
* New: API Key Lifecycle — optional expiry date (TTL) on any key; keys expire automatically via hourly cron; expired/archived status badges in the key table.
* New: Archive/Unarchive — hide inactive keys from the main list without deleting them; restore with one click (unarchived keys come back as suspended).
* New: Edit scopes — repurpose an existing key without revoking it; update scopes, IP allowlist, dry-run flag, and expiry date from a dedicated edit view.
* New: Rotate secret inside the edit form — optionally generate a fresh API key string as part of a scope-edit, with live diff preview of permission changes.
* New: Scope diff preview — while editing, the UI shows which scopes were added (+) and removed (−) compared to the original key, in real time.
* New: Full i18n — all admin strings wrapped for translation; POT template generated at languages/aicom.pot.

= 2.6.0 =
* New: Save custom presets — name and save any scope selection as a reusable preset that appears alongside the system presets. Custom presets are stored in the database and can be deleted with one click.

= 2.5.0 =
* New: Preset picker for key creation — 6 system presets (Read-only, Content Assistant, Elementor Editor, WooCommerce Catalog, Site Maintenance, Full Admin) plus Custom mode to auto-select common scope bundles with one click.
* New: Scope tree UI — scopes now grouped into 5 categories (WordPress Core, Media & Files, Users & Roles, Site Configuration, Integrations) with LOW/MED/HIGH/CRITICAL risk labels on every scope.
* New: Live search filter for scopes in the key creation form.
* New: Collapsible scope groups — click a group header to expand/collapse.

= 2.4.0 =
* New: AICOM Keys menu in the WordPress admin bar — lists all active and suspended API keys with one-click suspend/unsuspend via AJAX (no page reload). Shows a green badge with the count of active keys. Last item links to the full API Keys management page. Works in both wp-admin and frontend toolbar.

= 2.3.0 =
* New: elementor.page.create_from_template — create a new page by cloning Elementor data from a source page or template. Copies _elementor_data, _elementor_edit_mode, and _wp_page_template in one call. Supports dry_run and returns preview URL + admin edit URL.
* New: wp.posts.preview_url — get a preview URL for any post or page. Returns get_preview_post_link() for drafts/private, get_permalink() for published. Also includes admin_edit_url.

= 2.2.0 =
* New: Clautron module — 11 tools for blueprint and capability management (catalog.list/install, primitives.list, blueprint.examples/list/validate/create/compile/smoke_test, capability.meta.get/set). Requires Clautron plugin.
* New: Yoast SEO module — 9 tools for reading and writing Yoast SEO meta (yoast.post.get/set, yoast.post.social.get/set, yoast.posts.bulk_get for audits, yoast.term.get/set, yoast.site.get). Supports free and premium. Requires Yoast SEO plugin.

= 2.1.1 =
* Fix: wp.posts.create now accepts post_name (URL slug) and post_excerpt directly — no more 2-step create+update workaround.
* Fix: wp.posts.update now applies post_name and post_author — previously these were silently ignored despite returning updated:true.
* Fix: wp.posts.create defaults post_author to the user associated with the API key — prevents author=0 on REST-context requests.
* Fix: wp.posts.get now includes a terms map in the response, grouped by taxonomy (category, post_tag, custom taxonomies).
* New: wp.meta.set_many — set multiple post meta keys in one call. Accepts a meta object of key→value pairs; allowlist enforced per key.

= 2.1.0 =
* New: Ele Custom Skin (ECS) module — 26 tools for reading and writing ECS Color Schemes, Font Schemes, Custom Looks, Custom CSS, Alt Logos, and Dynamic Repeater Builder (DRB) presets and bindings. Works with both ele-custom-skin (free) and ele-custom-skin-pro. Activate a color scheme site-wide in one call via ecs.color_schemes.activate_global.

= 2.0.11 =
* Fix: wp.posts.update and wp.posts.create now support post_date parameter — previously the parameter was silently ignored and the tool returned success without changing the date. Accepts YYYY-MM-DD HH:MM:SS or ISO 8601; invalid format returns a clear error.
* Fix: wp.posts.update now also exposes post_excerpt in its input schema (was handled in code but not documented).

= 2.0.10 =
* Fix: replaced match() expression with if/elseif for PHP 7.4 compatibility — caused parse error on API Keys page for sites running PHP < 8.0

= 2.0.9 =
* New: Suspend/Unsuspend for API keys — temporarily block a key without revoking it. Suspended keys return 401 automatically (auth query filters status = active). Active keys show Suspend button; suspended keys show Unsuspend + Revoke.

= 2.0.8 =
* New: wp.plugins.list — list all installed plugins with version, update availability, and status. Optional force_refresh=true for a live check against wordpress.org.
* New: wp.plugins.update_all — update all plugins with available updates in one call (dry_run and include[] filter supported). Uses WordPress's native Plugin_Upgrader + Automatic_Upgrader_Skin, identical to background auto-updates.
* New scope: manage.plugins — dedicated scope for plugin management tools, separate from manage.wordpress.settings.

= 2.0.7 =
* New: elementor.template.set_conditions — dedicated tool that writes _elementor_conditions meta AND rebuilds the global elementor_pro_theme_builder_conditions option, then flushes the conditions cache. Uses Elementor Pro Conditions_Manager API when available, falls back to a manual option rebuild. Fixes Theme Builder templates not attaching to pages when conditions were set via wp.meta.set + wp.options.set.

= 2.0.6 =
* Fix: wp.meta.set now applies wp_slash() on string values before passing to update_post_meta() — prevents backslash stripping that broke Elementor JSON stored in post meta

= 2.0.5 =
* Fix: pll.string.set no longer calls PLL()->model->get_language() which is null in REST API context — replaced with direct pll_languages_list() lookup

= 2.0.4 =
* Fix: pll.strings.list, pll.string.get, pll.string.set no longer depend on pll_get_strings() (Polylang Pro only) — now works on Polylang free via direct PLL_MO access
* WordPress core strings (blogname, blogdescription, date_format, time_format) can be set per-language using wp_option parameter without Polylang Pro

= 2.0.3 =
* New: pll.strings.list — list all registered Polylang strings with current translations per language
* New: pll.string.get — get a specific string and all its translations
* New: pll.string.set — set the translation of a registered string for a specific language (supports dry-run)

= 2.0.2 =
* Fix: wp.menus.delete and wp.menus.items.remove now document confirm=true in their input schema — agents can now discover this requirement via tools/list
* Fix: wp.menus.items.add no longer requires url for custom type items — WordPress supports label-only menu items with an empty URL

= 2.0.1 =
* Fix: pll.post.link_translation and pll.term.link_translation now preserve existing translation group members when adding a new language — previously a third language (e.g. UK) was dropped when linking two posts
* Changed: link_translation tools now accept a translations map {"lang": id} instead of pairs, supporting any number of languages in a single call

= 2.0.0 =
* Complete rewrite with modular, autoloaded architecture
* 87 tools across 7 modules: WP Core, Media, Users, Backup, WooCommerce, Elementor, Polylang
* Full MCP JSON-RPC 2.0 support — `tools/call` and `tools/list` methods
* Shorthand request format also supported for simpler integrations
* Scope-based access control per API key — 12 granular scopes
* Hard lock / soft lock / unlocked safety modes switchable from admin
* Full audit logging: timestamp, IP, key label, tool, params, result, duration
* Dry-run mode — validate and simulate without applying changes
* Confirm flag required for all destructive operations
* IP allowlist per API key
* Backup and restore for posts and terms stored in database
* WooCommerce, Elementor, Polylang modules auto-activate when plugins present
* Fallback endpoint `/?aicom=1` for servers without mod_rewrite
* bcrypt-hashed API keys with prefix-based fast lookup
* Admin UI: Dashboard, API Keys, Audit Logs, Safety, Modules, Backups pages

== Upgrade Notice ==

= 2.0.0 =
Complete rewrite. After upgrading, re-generate all API keys — the key format has changed and old keys are not valid.
