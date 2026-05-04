=== AICOM - AI Commander ===
Contributors: dudaster
Tags: mcp, ai, automation, rest-api, ai-agent, claude, claude-code, openclaw, celine, goose
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 2.1.0
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Control WordPress with Claude Code, OpenClaw, Celine, Goose and any AI agent via MCP. API key auth, scope control, safety locks, audit logging and 87 tools.

== Description ==

**AICOM - AI Commander** turns your WordPress site into an MCP (Model Context Protocol) server, giving AI agents direct, structured access to your WordPress content, settings, and data.

Control your WordPress site through **Claude Code**, **OpenClaw**, **Celine**, **Goose**, and any other MCP-compatible AI agent. No more copy-pasting between your AI assistant and WordPress. No more manual repetitive tasks. Describe what you want, and your AI agent does it.

= What can you do with AICOM? =

* **AI-powered content creation** — let an AI agent write, update and publish posts, pages and custom post types directly on your site
* **Automate your WooCommerce store** — update product descriptions, manage categories and read settings through an AI agent without touching the dashboard
* **Manage multilingual sites** — connect with Polylang so AI agents can create and manage translations automatically
* **Control Elementor pages** — validate and inspect Elementor-built pages programmatically
* **Build AI editorial workflows** — draft, review, schedule and publish content via AI instructions
* **Bulk SEO tasks** — update meta fields, slugs, titles and descriptions in bulk via AI
* **Audit every AI action** — full log of every request: who, what, when, from which IP, with result

= Who is this for? =

* **Developers** building AI-powered WordPress tools or integrations
* **Agencies** automating client site management with AI agents
* **Content teams** using AI writing assistants and wanting direct WordPress integration
* **Claude Code users** — use AICOM as an MCP server directly from your terminal with Claude Code
* **OpenClaw users** — AICOM works with the OpenClaw AI platform as a native WordPress MCP connector
* **Celine & Goose users** — connect Celine or Goose to your WordPress site via AICOM's MCP endpoint
* **Anyone** using Claude, ChatGPT, Gemini, or other AI agents who wants them to directly control a WordPress site

= How it works =

AICOM exposes a secure HTTP endpoint on your WordPress site. AI platforms and agents send structured requests using the MCP / Model Context Protocol standard. AICOM authenticates the request, checks permissions, executes the operation, and returns a structured response.

`AI Agent → AICOM Endpoint → WordPress`

= Features =

* **MCP Standard** — Full JSON-RPC 2.0 support (`tools/call`, `tools/list`), compatible with any MCP client
* **87 tools** across 7 modules: WP Core, Media, Users, Backup, WooCommerce, Elementor, Polylang
* **Security-first** — API key authentication (bcrypt-hashed), IP allowlists, scope-based access control per key
* **Lock system** — Hard lock (read-only emergency mode), soft lock, unlocked — switchable from the WordPress admin
* **Audit logging** — Every request logged with duration, API key label, tool used, parameters and result summary
* **Dry-run mode** — Test what an operation would do without applying changes
* **Confirm flag** — Destructive operations require explicit `"confirm": true` — prevents accidental AI mistakes
* **Modular** — WooCommerce, Elementor and Polylang tools only activate when those plugins are present

= Available Modules & Tools =

* **WP Core** — server.status, wp.site.info, wp.posts.list/get/create/update/delete, wp.terms.*, wp.meta.*, wp.options.*
* **Media** — media.list, media.get, media.upload, media.update, media.delete, files.list/read/write
* **Users** — wp.users.list/get/create/update/delete, wp.roles.list
* **Backup** — backup.post, backup.term, backup.restore, backup.list, backup.delete, backup.purge
* **WooCommerce** *(optional)* — wc.products.list/get/create/update/delete, wc.categories.*, wc.settings.get/update
* **Elementor** *(optional)* — elementor.page.validate, elementor.page.get_data, elementor.widget.*
* **Polylang** *(optional)* — pll.languages.list, pll.post.translate, pll.term.translate, pll.string.*

= API Key Scopes =

Each API key is granted specific scopes — you control exactly what each AI agent can and cannot do:

`read.wp`, `write.wp.posts`, `manage.taxonomies`, `manage.meta`, `manage.wordpress.settings`, `manage.media`, `manage.users`, `manage.plugins`, `manage.woocommerce.products`, `manage.woocommerce.settings`, `manage.elementor`, `manage.polylang`

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

= Does it log what AI agents do? =

Yes. Every request is logged to the audit log with timestamp, remote IP, API key label, tool name, parameters, result summary, and response duration. The log is accessible from **AICOM → Audit Logs** and can be filtered by date, key, or tool name.

= What is MCP (Model Context Protocol)? =

MCP is an open standard created by Anthropic for connecting AI models to external tools and data sources. AICOM implements the MCP standard so any MCP-compatible AI client — Claude, OpenClaw, and others — can communicate with your WordPress site natively without custom integrations.

= Is this plugin free? =

Yes, completely free and open source under the GPL-2.0-or-later license.

= Can I restrict which IP addresses can use an API key? =

Yes. Each API key has an optional IP allowlist. If set, requests from any other IP will be rejected even if the key is valid.

== Screenshots ==

1. **Dashboard** — Real-time server status, MCP endpoint URL, lock state indicator, today's request count broken down by result, and list of active modules with tool counts.
2. **API Keys** — Generate new keys with a descriptive label, select granular scopes (read, write, manage per module), set an optional IP allowlist, and view all existing keys with their last-used date and status.
3. **Audit Logs** — Full request history filterable by date range, API key, and tool name. Each row shows timestamp, IP, key label, tool called, result status, and response time in ms.
4. **Safety Controls** — One-click Soft Lock and Hard Lock toggles with current lock status indicator. Includes the full Lock Permission Matrix showing which tool classes are allowed in each lock mode.
5. **Modules** — Overview cards for all 7 modules (WordPress Core, Media, Users, Backup, WooCommerce, Elementor, Polylang) with active/inactive status and tool count, followed by the complete list of all 87 registered tools with their class, required scopes, and flags.

== Changelog ==

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
