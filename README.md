# AICOM - AI Commander for WordPress

**Control your WordPress site with AI.** ACL is a WordPress plugin that turns your site into an MCP server — giving AI agents, automation tools, and platforms like OpenClaw direct, structured access to your WordPress content, settings, and data.

No more copy-pasting between your AI assistant and WordPress. No more manual repetitive tasks. Just describe what you want, and your AI agent does it.

---

## What can you do with AICOM?

- **Let an AI agent write and publish content** — create posts, pages, update metadata, manage categories and tags, all via natural language instructions to your AI.
- **Automate your WooCommerce store** — update product descriptions, manage categories, read settings, all through an AI agent without touching the WordPress dashboard.
- **Manage multilingual sites automatically** — connect with Polylang to create and manage translations via AI.
- **Control Elementor pages** — validate and inspect Elementor-built pages programmatically.
- **Build AI-powered editorial workflows** — let AI draft, review, schedule and publish content on your behalf.
- **Sync content across sites** — use AI agents to mirror or migrate content between WordPress installations.
- **Automate SEO tasks** — bulk update meta fields, slugs, titles and descriptions via AI instructions.
- **Monitor and audit your site** — every AI action is logged with full details: who, what, when, from which IP.

## Who is this for?

- **Developers** building AI-powered WordPress tools or integrations
- **Agencies** that want to automate client site management with AI
- **Content teams** using AI writing assistants and wanting direct WordPress integration
- **OpenClaw users** — ACL is the official WordPress connector for the OpenClaw AI platform
- **Anyone** using Claude, ChatGPT, or other AI agents who wants them to directly control a WordPress site

---

## How it works

ACL exposes a secure HTTP endpoint on your WordPress site. AI platforms and agents send structured requests to this endpoint (using the MCP / Model Context Protocol standard). ACL authenticates the request, checks permissions, executes the operation, and returns a structured response — all in milliseconds.

Your AI agent can list posts, create content, update settings, manage users, and much more — all without needing browser access or manual intervention.

```
AI Agent → ACL Endpoint → WordPress
```

---

## Features

- **MCP Standard** — Full JSON-RPC 2.0 support (`tools/call`, `tools/list`), compatible with any MCP client
- **87 tools** across 8 modules: WP Core, Menus, Media, Users, Backup, WooCommerce, Elementor, Polylang
- **Security-first** — API key authentication (bcrypt-hashed), IP allowlists, scope-based access control
- **Lock system** — Hard lock (read-only emergency mode), soft lock, unlocked — switchable from the WordPress admin
- **Audit logging** — Every request logged with duration, API key, tool used, parameters and result
- **Dry-run mode** — Test what an operation would do without applying changes
- **Confirm flag** — Destructive operations require explicit `confirm: true` — prevents accidental AI mistakes
- **Modular** — WooCommerce, Elementor and Polylang tools only activate when those plugins are present

---

## Installation

1. Upload the `aicom` folder to `/wp-content/plugins/`
2. Activate via **Plugins → Installed Plugins**
3. Go to **AICOM → API Keys** and create your first key
4. Point your AI agent or MCP client to your endpoint

---

## Endpoint

**REST API:**
```
POST /wp-json/aicom/v1/mcp
```

**Fallback** (always works, no mod_rewrite / .htaccess required):
```
POST /?aicom=1
```

**Health check:**
```
GET /?aicom=1
→ {"ok":true,"server":"AICOM - AI Commander for WordPress","version":"2.0.0"}
```

---

## Authentication

Pass your API key via header:
```
Authorization: Bearer aicom_XXXXXXXX_<secret>
```
or:
```
X-API-Key: aicom_XXXXXXXX_<secret>
```

> **Apache note:** Add `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` to `.htaccess` if the Authorization header is stripped by your server.

---

## MCP Request Format

**Standard (JSON-RPC 2.0):**
```json
{
  "jsonrpc": "2.0",
  "method": "tools/call",
  "params": {
    "name": "wp.posts.list",
    "arguments": { "post_type": "page", "posts_per_page": 10 }
  },
  "id": 1
}
```

**Shorthand:**
```json
{ "tool": "wp.posts.list", "arguments": { "post_type": "page" } }
```

---

## Modules & Tools

| Module | Tools | Dependency |
|--------|-------|------------|
| WP Core | server.status, wp.site.info, wp.posts.*, wp.terms.*, wp.meta.*, wp.options.* | — |
| Menus | wp.menus.*, wp.menus.items.* | — |
| Media | media.*, files.* | — |
| Users | wp.users.*, wp.roles.* | — |
| Backup | backup.* | — |
| WooCommerce | wc.products.*, wc.categories.*, wc.settings.* | WooCommerce |
| Elementor | elementor.page.*, elementor.widget.* | Elementor |
| Polylang | pll.* | Polylang |

---

## Scopes

API keys are granted specific scopes — you control exactly what each AI agent can and cannot do:

| Scope | Access |
|-------|--------|
| `read.wp` | Read posts, terms, meta |
| `write.wp.posts` | Create/update/delete posts |
| `manage.taxonomies` | Create/update terms |
| `manage.meta` | Read/write post meta |
| `manage.wordpress.settings` | Read/write options |
| `manage.menus` | Menu operations |
| `manage.media` | Media operations |
| `manage.users` | User management |
| `manage.woocommerce.products` | WooCommerce products |
| `manage.woocommerce.settings` | WooCommerce settings |
| `manage.elementor` | Elementor page editing |
| `manage.polylang` | Polylang translations |

---

## Requirements

- PHP 8.1+
- WordPress 6.0+
- Optional: WooCommerce, Elementor, Polylang

---

## Related

- [OpenClaw](https://openclaw.io) — AI agent platform with native ACL support
- [Model Context Protocol (MCP)](https://modelcontextprotocol.io) — the open standard ACL implements

---

## License

GPL-2.0-or-later
