# WP Ops MCP Gateway

A standalone MCP (Model Context Protocol) server for WordPress. Exposes WordPress operations via a secure HTTP endpoint with API key authentication, scope control, lock management, and full audit logging.

## Features

- **MCP Standard** — Full JSON-RPC 2.0 support (`tools/call`, `tools/list`)
- **87 tools** across 8 modules: WP Core, Menus, Media, Users, Backup, WooCommerce, Elementor, Polylang
- **Security** — API key authentication (hashed), IP allowlists, scope-based access control
- **Lock system** — Hard lock, soft lock, unlocked modes
- **Audit logging** — Every request logged with duration, key, tool, params, result
- **Dry-run mode** — Test operations without applying changes
- **Confirm flag** — Destructive operations require explicit `confirm: true`

## Installation

1. Upload the `wp-ops-mcp-gateway` folder to `/wp-content/plugins/`
2. Activate via **Plugins → Installed Plugins**
3. Go to **WP Ops MCP → API Keys** and create your first key

## Endpoint

```
POST /wp-json/wpops-mcp/v1/mcp
```

Fallback (always works, no mod_rewrite required):
```
POST /?wpops_mcp=1
```

Health check:
```
GET /?wpops_mcp=1
```

## Authentication

Pass your API key via header:
```
Authorization: Bearer wpops_XXXXXXXX_<secret>
```
or:
```
X-API-Key: wpops_XXXXXXXX_<secret>
```

> **Apache note:** Add `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` to `.htaccess` if the Authorization header is stripped.

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

## Scopes

API keys are granted specific scopes:

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

## Requirements

- PHP 8.1+
- WordPress 6.0+
- Optional: WooCommerce, Elementor, Polylang

## License

GPL-2.0-or-later
