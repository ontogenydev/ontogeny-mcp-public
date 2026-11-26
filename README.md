# ONTOGENY MCP / 0.1.1

First-party Model Context Protocol interface for ONTOGENY. Public reference implementation licensed under [GPL-2.0-or-later](LICENSE). Version 0.1.1 corrects credential administration concurrency and keeps production configuration separate. Private routing and private Git history are excluded.

## Deployment contract

- Target endpoint (not provisioned): `https://mcp.ontogenyai.com/mcp`
- Initial WordPress endpoint (available after plugin installation and activation): `https://ontogenyai.com/wp-json/ontogeny-mcp/v1/mcp`
- Human documentation (publish a WordPress page with the exact slug `mcp` after activating the plugin): `https://ontogenyai.com/mcp/`
- Public repository: `ontogenydev/ontogeny-mcp-public`
- Transport: JSON-only Streamable HTTP, JSON-RPC 2.0, 2025-11-25, 2025-06-18, 2025-03-26. No SSE, server-to-client requests, session identifier or OAuth discovery. A client that only supports newer revisions must negotiate a compatible revision. Direct HTTP clients with custom Authorization headers can use the restricted send pilot. This release does not claim universal client compatibility.

## Tools

| Name | Access | Function |
|---|---|---|
| `get_organization` | Public | Public identity and site information |
| `list_systems` | Public | Only documented systems (StrataDOME) |
| `get_system` | Public | Dossier, public documentation links (`system_id: stratadome`) |
| `get_contact_channels` | Public | Email address, website and inquiry categories |
| `prepare_correspondence` | Public preview / scoped ticket | Validate subject and message; never sends |
| `send_correspondence` | Restricted | Send an exactly prepared draft with an unexpired ticket and explicit client-confirmed approval |

Only StrataDOME is listed. No future programs, private implementation, administration, or claims of production readiness are included. The dossier is a versioned snapshot of the existing public wording; update it deliberately when the website changes.

## Install

Requires PHP >= 8.1 and a WordPress installation supporting custom plugins and REST routes. Upload the directory-packaged plugin ZIP under Plugins > Add New > Upload Plugin, activate, and verify the database table is installed. The REST namespace is independent of the active theme. Correspondence is disabled until a valid deployment-owned recipient is configured as `ONTOGENY_MCP_MAIL_RECIPIENT` in `wp-config.php` or a deployment-only `config/local.php` (see `config/wp-config.example.php`). Use only an inbox you control; do not deploy this reference build over a live service without supplying and validating its private configuration. Create/publish a page titled MCP with slug `mcp` (the plugin supplies its documentation template). No new top-level navigation item is added to existing pages in this release. The docs page uses existing MOECA assets when available. The documentation can be published in preliminary deployment status; do not mark the endpoint operational until a live protocol smoke test succeeds.

Example VS Code `.vscode/mcp.json` for public access:

```json
{"servers":{"ontogeny":{"type":"http","url":"https://ontogenyai.com/wp-json/ontogeny-mcp/v1/mcp"}}}
```

Initialize using `POST` with `Content-Type: application/json` and `Accept: application/json, text/event-stream`:

```json
{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"test","version":"1"}}}
```

After initialization send `notifications/initialized` (202 empty body), then use `MCP-Protocol-Version: 2025-11-25` with `tools/list`, `tools/call`, and `ping`. GET returns 405 because no SSE stream is offered. Browser Origins outside the ONTOGENY allowlist are rejected. No cross-origin browser access is enabled. Public read clients do not require a secret. Public read requests are size-limited; enforce additional host-level WAF quotas before broad public promotion.

## Restricted correspondence

The administrator manually verifies ownership of the intended reply address (outside the server), then uses Settings > ONTOGENY MCP to issue a 30-day, revocable, random credential. Only a SHA-256 hash of the credential is stored; the bound email and expiry are stored in individual private WordPress options. The administrator list is derived directly from those authoritative records; a separate aggregate index is no longer used. New authorization lookups after committed revocation fail; operations already authorized can finish. Share the credential securely. Set `Authorization: Bearer <credential>` in a compatible client. No WordPress admin token or password is accepted by the MCP dispatcher. Do not include tokens in URLs, public configuration, logs or source control.

1. Call `prepare_correspondence` with `subject` and `message`. Subject <=160 UTF-8 bytes; message <=10000 UTF-8 bytes. Without a valid token, preview only. With a valid token, the server returns a signed ticket valid for ten minutes.
2. Show the exact draft, reply address, and destination to the human and request explicit approval. The trusted client must obtain and enforce approval; the server cannot independently establish that a human clicked approve. Do not issue tokens to untrusted unattended agents.
3. Call `send_correspondence` with identical subject/message, ticket, and `approved: true`. The backend checks credential, exact content and expiry; an atomic SQL reservation prevents repeated mail handoffs for a ticket. Per-credential limits: 2/hour, 5/day; global: 20/day (UTC fixed windows). Quota failures consume the ticket. Do not automatically retry uncertain mail handoffs. A new ticket can send a new message; ticket idempotency is not a universal duplicate-message detector.
4. An `accepted_by_mail_service` result means WordPress accepted the message for sending, not that it reached the inbox. `mail_handoff_failed`, `pending_unknown`, or `already_accepted` are explicit outcomes; check the mailbox before deciding whether to prepare another draft.

The actual recipient is a single deployment-owned mailbox, configured outside the repository. The default is disabled; clients cannot specify a destination. The public identity remains `contact@ontogenyai.com`; sender authentication remains the WordPress service identity. Reply-To is the credential-bound address, not an arbitrary caller argument. No message bodies are persisted by this plugin. Quota/receipt state is cleaned daily after expiry (WP-Cron must run); credential records are removed on expiry or revocation. Mail transport and hosting can have their own retention. On deactivation the route is removed; credential/receipt data remains for rollback. Revoke credentials before permanent retirement. Plugin activation has no automatic mail action.

## Subdomain and repository

Do not change DNS until an actual HTTPS reverse proxy or dedicated service has been provisioned with TLS for `mcp.ontogenyai.com`. The proxy must forward POST bodies, Content-Type, Accept, MCP-Protocol-Version and Authorization to the WordPress endpoint without redirects or caching. GET must preserve 405, and notifications 202 with no body. Validate Origin and host; never expose the WordPress administrative interface. Do not route DNS to a guessed host/IP. The canonical private repository and reviewed public snapshot are separately maintained. Never merge or push private Git history into the public repository. See `docs/RELEASING.md`. This release is licensed under GPL-2.0-or-later; see LICENSE and NOTICE.md.

## Verification

`php tests/test.php` runs the 42 isolated protocol checks; `php tests/config-test.php` checks the public backend configuration using stubbed WordPress functions and fake mail. Neither sends real mail. `php tests/http-router.php` is not a production server; `php -S 127.0.0.1:8088 tests/http-router.php` provides a local loopback fixture for HTTP tests. No live emails are sent by the tests. The v0.1.0 pilot demonstrated scoped live initialization, public invocation, authorized correspondence delivery and replay protection. The credential correction passed isolated real-database testing before final version/configuration packaging. Use the included tests to validate your installation; production verification of a new version is separate. Run a controlled correspondence test with a verified pilot credential before granting write access.

Protocol reference: https://modelcontextprotocol.io/specification/2025-11-25/basic/transports and https://modelcontextprotocol.io/specification/2025-11-25/basic/lifecycle.

For real WordPress/MariaDB integration and concurrent credential tests, see [local integration](tests/integration/README.md). No external mail is transmitted by that lab.
