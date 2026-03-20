=== AICOM - AI Commander for WordPress ===
Contributors: dudaster
Tags: mcp, ai, automation, rest-api, ai-agent
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 2.0.0
Requires PHP: 8.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect AI agents to your WordPress site. MCP server plugin with API key auth, scope control, lock system, audit logging and 87 tools.

== Description ==

**AICOM - AI Commander for WordPress** turns your WordPress site into an MCP (Model Context Protocol) server, giving AI agents, automation tools, and platforms like OpenClaw direct, structured access to your WordPress content, settings, and data.

No more copy-pasting between your AI assistant and WordPress. No more manual repetitive tasks. Describe what you want, and your AI agent does it.

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
* **OpenClaw users** — ACL is the official WordPress connector for the OpenClaw AI platform
* **Anyone** using Claude, ChatGPT, Gemini, or other AI agents who wants them to directly control a WordPress site

= How it works =

ACL exposes a secure HTTP endpoint on your WordPress site. AI platforms and agents send structured requests using the MCP / Model Context Protocol standard. ACL authenticates the request, checks permissions, executes the operation, and returns a structured response.

`AI Agent → ACL Endpoint → WordPress`

= Features =

* **MCP Standard** — Full JSON-RPC 2.0 support (`tools/call`, `tools/list`), compatible with any MCP client
* **87 tools** across 8 modules: WP Core, Menus, Media, Users, Backup, WooCommerce, Elementor, Polylang
* **Security-first** — API key authentication (bcrypt-hashed), IP allowlists, scope-based access control per key
* **Lock system** — Hard lock (read-only emergency mode), soft lock, unlocked — switchable from the WordPress admin
* **Audit logging** — Every request logged with duration, API key label, tool used, parameters and result summary
* **Dry-run mode** — Test what an operation would do without applying changes
* **Confirm flag** — Destructive operations require explicit `"confirm": true` — prevents accidental AI mistakes
* **Modular** — WooCommerce, Elementor and Polylang tools only activate when those plugins are present

= Available Modules & Tools =

* **WP Core** — server.status, wp.site.info, wp.posts.list/get/create/update/delete, wp.terms.*, wp.meta.*, wp.options.*
* **Menus** — wp.menus.list, wp.menus.create, wp.menus.items.add/update/delete
* **Media** — media.list, media.get, media.upload, media.update, media.delete, files.list/read/write
* **Users** — wp.users.list/get/create/update/delete, wp.roles.list
* **Backup** — backup.post, backup.term, backup.restore, backup.list, backup.delete, backup.purge
* **WooCommerce** *(optional)* — wc.products.list/get/create/update/delete, wc.categories.*, wc.settings.get/update
* **Elementor** *(optional)* — elementor.page.validate, elementor.page.get_data, elementor.widget.*
* **Polylang** *(optional)* — pll.languages.list, pll.post.translate, pll.term.translate, pll.string.*

= API Key Scopes =

Each API key is granted specific scopes — you control exactly what each AI agent can and cannot do:

`read.wp`, `write.wp.posts`, `manage.taxonomies`, `manage.meta`, `manage.wordpress.settings`, `manage.menus`, `manage.media`, `manage.users`, `manage.woocommerce.products`, `manage.woocommerce.settings`, `manage.elementor`, `manage.polylang`

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

1. Upload the `aicom` folder to `/wp-content/plugins/`
2. Activate the plugin via **Plugins → Installed Plugins**
3. Go to **AICOM → API Keys** and create your first API key
4. Select the scopes you want to grant
5. Point your AI agent or MCP client to `https://yoursite.com/wp-json/aicom/v1/mcp`
6. Pass the key as `Authorization: Bearer <your-key>` header

**Apache note:** If the Authorization header is stripped by your server, add this to `.htaccess`:
`SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`

== Frequently Asked Questions ==

= Does this plugin make my site publicly accessible to anyone? =

No. Every request must include a valid API key. Keys are bcrypt-hashed in the database and scoped — each key only has access to the specific operations you explicitly grant it.

= Does it work without mod_rewrite? =

Yes. The fallback endpoint `/?aicom=1` works on any server configuration, with or without pretty permalinks.

= Is it compatible with WooCommerce / Elementor / Polylang? =

Yes. Each plugin's tools are loaded automatically only if the corresponding plugin is active. If WooCommerce is not installed, no WooCommerce tools appear.

= Can I restrict an AI agent to read-only access? =

Yes, in two ways: (1) assign only `read.wp` scopes to the key, or (2) enable **Hard Lock** mode from the Safety page — this blocks all write operations site-wide regardless of key scopes.

= Can I test operations before they run? =

Yes. Send `"dry_run": true` in your request. The operation will be validated and simulated but no data will be changed.

= Does it log what AI agents do? =

Yes. Every request is logged to the audit log with timestamp, remote IP, API key label, tool name, parameters, result summary, and duration. Accessible from **AICOM → Audit Logs**.

= What is MCP (Model Context Protocol)? =

MCP is an open standard by Anthropic for connecting AI models to external tools and data sources. ACL implements MCP so any MCP-compatible AI client (Claude, OpenClaw, and others) can communicate with your WordPress site natively.

= Is this free? =

Yes, completely free and open source under GPL-2.0-or-later.

== Screenshots ==

1. Dashboard overview — server status, lock state, today's request stats
2. API Keys management — create keys with fine-grained scope selection
3. Audit Logs — full request history with filtering
4. Safety page — lock system controls
5. Modules page — active module status

== Changelog ==

= 2.0.0 =
* Full rewrite with modular architecture
* 87 tools across 8 modules
* MCP JSON-RPC 2.0 standard support
* Scope-based access control per API key
* Hard lock / soft lock / unlocked safety modes
* Full audit logging with duration and result summary
* Dry-run mode and confirm flag for destructive operations
* WooCommerce, Elementor, Polylang module support
* IP allowlist per API key
* Backup and restore for posts and terms

== Upgrade Notice ==

= 2.0.0 =
Complete rewrite. After upgrading, re-generate all API keys as the key format has changed.
