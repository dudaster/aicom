# AICOM — AI Commander for WordPress

> **Control your WordPress site with any AI agent.** AICOM turns your site into an MCP server — giving AI agents, automation tools, and platforms like Claude Code, OpenClaw, Celine and Goose direct, structured access to your WordPress content, settings, and data.

[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue)](https://wordpress.org/plugins/aicom/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple)](https://php.net)
[![License](https://img.shields.io/badge/License-GPL--2.0--or--later-green)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WordPress Plugin](https://img.shields.io/badge/WordPress.org-AICOM-blue)](https://wordpress.org/plugins/aicom/)

---

## What can you do with AICOM?

- **AI-powered content creation** — write, update and publish posts, pages and custom post types via AI
- **Automate your WooCommerce store** — update products, manage categories, read settings through an AI agent
- **Manage multilingual sites** — connect Polylang so AI agents can create and manage translations automatically
- **Control Elementor pages** — update widget content, set Theme Builder conditions, validate and restore pages
- **Build AI editorial workflows** — draft, review, schedule and publish content via AI instructions
- **Bulk SEO tasks** — update meta fields, slugs, titles and descriptions in bulk via AI
- **Maintain your plugins** — list installed plugins, check for updates and update them all in one AI call
- **Audit every AI action** — full log of every request: who, what, when, from which IP, with result

## How it works

```
AI Agent → AICOM Endpoint → WordPress
```

AICOM exposes a secure HTTP endpoint. AI platforms send structured [MCP](https://modelcontextprotocol.io) / JSON-RPC 2.0 requests. AICOM authenticates the request, checks permissions, executes the operation, and returns a structured response.

---

## Installation

1. Install from [WordPress.org/plugins/aicom](https://wordpress.org/plugins/aicom/) or upload the `aicom` folder to `/wp-content/plugins/`
2. Activate via **Plugins → Installed Plugins**
3. Go to **AICOM → API Keys** and click **Generate New Key**
4. Select scopes, copy the key (shown once), point your agent at the endpoint

---

## Endpoint

| Type | URL |
|------|-----|
| Primary (REST API) | `POST /wp-json/aicom/v1/mcp` |
| Fallback (no mod_rewrite needed) | `POST /?aicom=1` |
| Health check | `GET /?aicom=1` |

**Apache note:** If the `Authorization` header is stripped, add to `.htaccess`:
```
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
```

---

## Authentication

```
Authorization: Bearer aicom_XXXXXXXX_<secret>
```
or:
```
X-API-Key: aicom_XXXXXXXX_<secret>
```

---

## MCP Request Format

**Standard JSON-RPC 2.0:**
```json
{
  "jsonrpc": "2.0",
  "method": "tools/call",
  "params": {
    "name": "wp.posts.list",
    "arguments": { "post_type": "post", "posts_per_page": 10 }
  },
  "id": 1
}
```

**List tools:**
```json
{ "jsonrpc": "2.0", "method": "tools/list", "params": {}, "id": 1 }
```

**Shorthand:**
```json
{ "tool": "wp.posts.list", "arguments": { "post_type": "post" } }
```

---

## Modules & Tools

| Module | Tools | Dependency |
|--------|-------|------------|
| **WP Core** | `server.status`, `wp.site.info`, `wp.post_types.list`, `wp.taxonomies.list`, `wp.posts.list/get/create/update/trash/restore/delete`, `wp.terms.list/get/create/update/delete/assign_to_post/remove_from_post`, `wp.meta.get/set/delete`, `wp.options.get/set`, `wp.plugins.list/update_all` | — |
| **Menus** | `wp.menus.list/get/create/update/delete`, `wp.menus.items.list/add/update/remove` | — |
| **Media** | `media.list/get/upload/update/delete`, `files.list/read/write` | — |
| **Users** | `wp.users.list/get/create/update/delete`, `wp.roles.list/create/update_caps/delete` | — |
| **Backup** | `backup.post/term/restore/list/delete/purge` | — |
| **WooCommerce** | `wc.products.list/get/create/update/delete`, `wc.categories.list/get/create/update/delete`, `wc.settings.get/update` | WooCommerce |
| **Elementor** | `elementor.page.get_tree/get_texts/backup/restore/validate/regenerate_assets`, `elementor.widget.update_field`, `elementor.page.bulk_update_texts`, `elementor.template.set_conditions` | Elementor |
| **Polylang** | `pll.languages.list`, `pll.post.translate/link_translation`, `pll.term.translate/link_translation`, `pll.strings.list/get/set` | Polylang |

---

## Scopes

Each API key is granted specific scopes — you control exactly what each AI agent can and cannot do:

| Scope | Access |
|-------|--------|
| `read.wp` | Read posts, terms, meta |
| `write.wp.posts` | Create/update posts |
| `delete.wp.posts` | Trash/delete posts |
| `manage.taxonomies` | Create/update/delete terms |
| `manage.meta` | Read/write post meta |
| `manage.wordpress.settings` | Read/write WP options |
| `manage.menus` | Nav menu operations |
| `manage.media` | Media library operations |
| `manage.files` | Filesystem read/write (restricted) |
| `manage.plugins` | List plugins and run updates |
| `read.users` | Read user list/profile |
| `manage.users` | Create/update users |
| `delete.users` | Delete users |
| `manage.roles` | Create/update/delete roles and capabilities |
| `manage.backups` | Backup and restore operations |
| `manage.woocommerce.products` | WooCommerce products and categories |
| `manage.woocommerce.settings` | WooCommerce settings |
| `manage.elementor` | Elementor page editing and Theme Builder |
| `manage.polylang` | Polylang translations and strings |

---

## Security Features

- **API key authentication** — bcrypt-hashed keys with prefix-based fast lookup
- **Scope-based access control** — each key has only the scopes you explicitly grant
- **IP allowlist per key** — optionally restrict keys to specific IPs or CIDR ranges
- **Key suspend/unsuspend** — temporarily block a key without revoking it
- **Hard Lock** — emergency read-only mode: only `public` tools allowed, blocks all writes site-wide
- **Soft Lock** — blocks all write/destructive tools site-wide regardless of key scopes
- **Confirm flag** — destructive operations require explicit `"confirm": true`
- **Dry-run mode** — simulate any operation without applying changes (`"dry_run": true`)
- **Audit log** — every request logged: timestamp, IP, key label, tool, params, result, duration

---

## Lock System

| Mode | Allowed tool classes |
|------|---------------------|
| **Unlocked** | All tools |
| **Soft Lock** | `public`, `discovery`, `read` only |
| **Hard Lock** | `public` only (`server.status`) |

Switchable from **AICOM → Safety** in the WordPress admin.

---

## Usage Examples

### Claude Code (via MCP config)

```json
{
  "mcpServers": {
    "my-wordpress": {
      "command": "npx",
      "args": ["-y", "@modelcontextprotocol/server-fetch"],
      "env": {
        "MCP_ENDPOINT": "https://yoursite.com/wp-json/aicom/v1/mcp",
        "MCP_API_KEY": "aicom_XXXXXXXX_your_secret"
      }
    }
  }
}
```

### Direct API call

```bash
curl -X POST https://yoursite.com/wp-json/aicom/v1/mcp \
  -H "Authorization: Bearer aicom_XXXXXXXX_your_secret" \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "method": "tools/call",
    "params": {
      "name": "wp.posts.create",
      "arguments": {
        "post_title": "Hello from AI",
        "post_status": "draft",
        "post_content": "This post was created by an AI agent."
      }
    },
    "id": 1
  }'
```

### Plugin maintenance agent

```bash
# List plugins with available updates
{ "tool": "wp.plugins.list", "arguments": {} }

# Update all plugins (dry run first)
{ "tool": "wp.plugins.update_all", "arguments": { "dry_run": true, "confirm": true } }

# Apply updates
{ "tool": "wp.plugins.update_all", "arguments": { "confirm": true } }
```

### Elementor Theme Builder via AI

```bash
# Create header template
{ "tool": "wp.posts.create", "arguments": { "post_type": "elementor_library", "post_title": "Site Header", "post_status": "publish" } }

# Set template type and conditions
{ "tool": "elementor.template.set_conditions", "arguments": { "post_id": 123, "template_type": "header", "conditions": ["include/general"] } }
```

---

## Requirements

- PHP 7.4+
- WordPress 6.0+
- Optional: WooCommerce, Elementor (free or Pro), Polylang (free or Pro)

---

## Changelog

See [readme.txt](readme.txt) for the full changelog.

---

## License

GPL-2.0-or-later — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html)

---

## Links

- [WordPress.org plugin page](https://wordpress.org/plugins/aicom/)
- [Model Context Protocol](https://modelcontextprotocol.io)
- [Report a bug](https://github.com/dudaster/aicom/issues)
