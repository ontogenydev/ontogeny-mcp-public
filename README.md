# ONTOGENY MCP / 0.2.0

GPL-2.0-or-later. JSON-only Streamable HTTP. Existing endpoint and supported protocol revisions are unchanged. No registration, OAuth flow, or manually issued API credential is required. The six public tool names are unchanged; `send_correspondence` now requires `reply_email` in addition to `subject`, `message`, `ticket`, `approved`. Refresh tool discovery after the upgrade. Legacy v0.1.1 tickets and credentials no longer authorize a send. Legacy records remain inert for rollback; revoke before restoring old versions if appropriate.

Call `prepare_correspondence` with subject, message and your reply_email. Preparation never sends mail. Without a reply address it returns a preview only; when the destination is configured and sending enabled, a valid address yields a ten-minute signed ticket bound to the exact draft and reply address. Show the full draft, claimed reply address and destination to the human; obtain explicit approval in the trusted client. The server checks the boolean approval claim but cannot prove that the user approved it. Call `send_correspondence` with the same values and approved=true. A successful result means mail-service acceptance, not delivery. Do not retry unknown outcomes. A new ticket is not a guarantee against sending a semantically duplicate message.

No mail is sent to the supplied reply address, and it is not placed in a mail header. It is included as an unverified claim in the body. The deployment-owned fixed recipient is configured outside source. The reference runtime does not send if its recipient is missing or invalid. Define ONTOGENY_MCP_PUBLIC_SEND_ENABLED as boolean false to disable public transmission without removing reads; if defined, only boolean true enables it. Missing flag defaults to enabled when a valid recipient is configured.

Quota windows are UTC fixed windows: 5/hour, 10/day per server-observed source (IPv6 /64; IPv4-mapped addresses normalized); 2/hour, 5/day per case-folded claimed reply address; 20/day globally (existing quota key retained); 200/day global new-attempt admission cap to bound state growth. Only server-provided REMOTE_ADDR is used; proxy deployments may share a conservative source bucket. No caller-supplied forwarding headers are trusted. A quota rejection after reservation consumes that ticket. A rejection before admission leaves no receipt and never sends. Concurrent duplicate calls can consume extra admission quota, but unique reservation prevents multiple mail handoffs for a ticket. Quotas bound abuse and storage growth; they cannot eliminate spam or quota-exhaustion denial of service. Apply hosting-layer rate limits/WAF after monitoring shared-proxy behavior. Tickets and bucket identifiers use version-domain HMACs; raw reply addresses and bodies are not persisted in MCP quota rows. Host and mail transport logging/retention are separate. Cron cleanup is retained.

## Installation and connection

Requires PHP 8.1+ and WordPress with REST routes and custom plugins. Package the runtime (ontogeny-mcp.php, src/, docs-template.php, LICENSE, NOTICE.md and VERSION) in an `ontogeny-mcp/` directory. Do not deploy test runners or a public reference ZIP over an existing private configuration without preserving it. Configure the fixed recipient in wp-config.php or a deployment-only config/local.php; see config/wp-config.example.php. Activate the plugin and publish a WordPress page with slug `mcp` for the documentation template. The route namespace remains `ontogeny-mcp/v1`.

Official endpoint: `https://ontogenyai.com/wp-json/ontogeny-mcp/v1/mcp`. Documentation: https://ontogenyai.com/mcp/. Reserved subdomain target `https://mcp.ontogenyai.com/mcp` is not provisioned. Public repository: https://github.com/ontogenydev/ontogeny-mcp-public.

Transport: JSON-only Streamable HTTP, JSON-RPC 2.0. Supported revisions: 2025-11-25, 2025-06-18, 2025-03-26. Send Content-Type: application/json and Accept: application/json, text/event-stream. Initialize, send notifications/initialized (empty 202), then use the negotiated MCP-Protocol-Version header. GET and DELETE return 405. No SSE, session ID, OAuth discovery, or arbitrary cross-origin browser access. Universal client compatibility is not claimed.

Example VS Code `.vscode/mcp.json`:

```json
{"servers":{"ontogeny":{"type":"http","url":"https://ontogenyai.com/wp-json/ontogeny-mcp/v1/mcp"}}}
```

| Tool | Function |
| --- | --- |
| get_organization | Public identity |
| list_systems | Public systems only |
| get_system | StrataDOME dossier (`system_id: stratadome`) |
| get_contact_channels | Public correspondence options |
| prepare_correspondence | Draft preview and ticket issuance |
| send_correspondence | Submit the exact approved draft to the fixed destination |

## Verification

Run `php tests/test.php`, `php tests/config-test.php`, and `php tests/storage-test.php` (50, 14 and 18 checks respectively; no real mail). `tests/http-router.php` is a local loopback-only fixture with sending disabled. The deployment preflight `tests/wp-db-preflight.php` uses a temporary DB table; read its requirements before running in a controlled WordPress environment. `tests/live-audit.php` targets the official deployment and should only be run by its operator; the default mode performs non-mailing checks, while `--send-test` requires explicit interactive approval of a single real message. Do not run repeated production sends.

Release acceptance: 82 isolated checks, seven real-database state checks, and 25 live protocol/correspondence checks passed. A single controlled message reached the ONTOGENY inbox, and a same-ticket replay was rejected. Concurrent real-database behavior under simultaneous traffic has not yet been independently tested. The prior credential-based integration suite belongs to v0.1.1 and is not an acceptance test for this version.

Protocol reference: https://modelcontextprotocol.io/specification/2025-11-25/basic/transports.
