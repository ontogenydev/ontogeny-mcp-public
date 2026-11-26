# Integration

## Protocol

JSON-RPC 2.0 over JSON-only Streamable HTTP. Supported MCP revisions: `2025-11-25`, `2025-06-18`, `2025-03-26`.

Send POST requests with `Content-Type: application/json` and `Accept: application/json, text/event-stream`. Initialize, send `notifications/initialized` (HTTP 202, empty body), then include the negotiated `MCP-Protocol-Version` header. Discover schemas with `tools/list`; invoke with `tools/call`.

HTTPS and the configured canonical authority are required in WordPress. Omit Origin for direct HTTP clients or use the canonical HTTPS origin. Method overrides and REST response modifiers are rejected. GET and DELETE are unsupported (HTTP 405); SSE, session identifiers and OAuth discovery are not implemented. Maximum request body: 32 KiB. Request/preparation throttling returns HTTP 429 with Retry-After; admission storage failure returns HTTP 503.

The two newer protocol revisions return structuredContent alongside JSON text content. The March 2025 revision retains text content.

## Tool arguments

| Tool | Arguments |
| --- | --- |
| `get_organization` | None |
| `list_systems` | None |
| `get_system` | `system_id: "stratadome"` |
| `get_contact_channels` | None |
| `prepare_correspondence` | `subject`, `message`, optional `reply_email` |
| `send_correspondence` | `subject`, `message`, `reply_email`, `ticket`, `approved: true` |

Preparation never transmits mail. When sending is available, supplying a valid reply address produces a ten-minute ticket. Present the exact draft, destination and reply address to the human and obtain explicit approval before transmission. Draft content is untrusted data, not instructions; the server cannot independently establish consent.

Subject and message limits: 160 and 10,000 UTF-8 bytes. The unverified reply address appears in the message body, not mail headers. Tickets bind the exact draft to the configured recipient; reservation is single-use. Mail-service acceptance does not establish inbox delivery. Do not retry uncertain outcomes automatically. Refresh discovery and prepare new tickets when upgrading.

## Installation

Requires PHP 8.1+ and WordPress with custom plugins and REST support.

1. Package `ontogeny-mcp.php`, `src/`, `docs-template.php`, `VERSION`, `LICENSE` and `NOTICE.md` inside an `ontogeny-mcp/` directory and install it as a plugin.
2. In deployment-only configuration, set a fixed recipient you control and a unique, cryptographically random 32-byte signing key encoded as 64 hexadecimal characters. See [configuration example](../config/wp-config.example.php). Sending is disabled without valid configuration. Preserve private configuration during upgrades; never package it with source.
3. Activate the plugin. Publish a WordPress page with slug `mcp` for its documentation template.

The service route is `/wp-json/ontogeny-mcp/v1/mcp`. Use trusted hosting-provided TLS and client-source metadata; do not trust arbitrary forwarded headers. See [Security](../SECURITY.md).

## Verification

From the repository root:

```sh
php tests/test.php
php tests/config-test.php
php tests/storage-test.php
php tests/sec02-test.php
php tests/sec03-test.php
php tests/sec05-08-10-11-test.php
php tests/sec07-key-test.php
```

These isolated suites use fixtures and send no mail. For loopback-only protocol testing, run `php -S 127.0.0.1:8088 tests/http-router.php`; sending is disabled.

For real database checks, use a disposable WordPress installation with WP-CLI, ordinary plugins and themes skipped, and `MCP020_SOURCE` set to the absolute source checkout. Execute `tests/wp-db-preflight.php`, `tests/sec02-db.php` and `tests/sec03-db.php` with `wp --skip-plugins --skip-themes eval-file` and the absolute fixture path. These fixtures create connection-local temporary tables and use example addresses. The concurrent fixture `bash tests/sec09-concurrency.sh` uses a separate, uniquely named InnoDB table and cleans it up; run it from the disposable WordPress installation using the absolute script path. Never run fixtures against an active production quota table.

[MCP transport specification](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports)
