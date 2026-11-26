# Integration

## Protocol

The service implements JSON-RPC 2.0 over JSON-only Streamable HTTP. Supported MCP revisions: `2025-11-25`, `2025-06-18`, `2025-03-26`.

Send POST requests with `Content-Type: application/json` and `Accept: application/json, text/event-stream`. Initialize the connection, send `notifications/initialized` (HTTP 202, empty body), then use the negotiated `MCP-Protocol-Version` header for subsequent requests. Discover tool schemas through `tools/list` and invoke tools through `tools/call`.

GET and DELETE are unsupported (HTTP 405). SSE streams, session identifiers and OAuth discovery are not implemented. The maximum request body is 32 KiB.

## Tool arguments

| Tool | Arguments |
| --- | --- |
| `get_organization` | None |
| `list_systems` | None |
| `get_system` | `system_id: "stratadome"` |
| `get_contact_channels` | None |
| `prepare_correspondence` | `subject`, `message`, optional `reply_email` |
| `send_correspondence` | `subject`, `message`, `reply_email`, `ticket`, `approved: true` |

Preparation is non-transmitting. When correspondence is available, supplying a valid reply address produces a ten-minute ticket. Present the complete draft, destination and reply address to the human; obtain explicit approval before sending the identical draft with its ticket. The client enforces human approval. The server cannot independently establish consent.

Subject and message limits are 160 and 10,000 UTF-8 bytes respectively. Reply addresses are unverified claims, not authenticated identities; they appear in the message body, not mail headers. Tickets are single-use and bound to the exact draft. Rate limits apply. Mail-service acceptance does not establish inbox delivery; do not automatically retry uncertain outcomes. Refresh discovery when upgrading from v0.1.1; legacy credentials and tickets cannot authorize transmission.

## Installation

Requires PHP 8.1+ and WordPress with custom plugins and REST support.

1. Package `ontogeny-mcp.php`, `src/`, `docs-template.php`, `VERSION`, `LICENSE` and `NOTICE.md` inside an `ontogeny-mcp/` directory and install it as a WordPress plugin.
2. Configure a fixed recipient you control in your deployment configuration; see [configuration example](../config/wp-config.example.php). Sending is disabled without a valid recipient. Preserve deployment configuration during upgrades.
3. Activate the plugin. Publish a WordPress page with slug `mcp` for its documentation template.

The route is `/wp-json/ontogeny-mcp/v1/mcp` on your installation. See [Security](../SECURITY.md) for operating considerations.

## Verification

Run the isolated suites from the repository root:

```sh
php tests/test.php
php tests/config-test.php
php tests/storage-test.php
```

These tests do not send real mail. For local transport testing, run `php -S 127.0.0.1:8088 tests/http-router.php`. Sending is disabled in this fixture.

The database fixture (`tests/wp-db-preflight.php`) uses a temporary table and sends no mail. Run it in a disposable WordPress environment with a configured test recipient, WP-CLI, and `MCP020_SOURCE` and `MCP020_LIVE` set to the relevant local source and plugin directories. Use `wp --skip-plugins --skip-themes eval-file tests/wp-db-preflight.php` from the WordPress installation (or provide the fixture's absolute path). Simultaneous real-database concurrency has not yet been independently validated.

[MCP transport specification](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports)
