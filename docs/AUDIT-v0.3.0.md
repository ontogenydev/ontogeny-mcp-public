# ONTOGENY MCP v0.3.0 scope audit

Reviewed 2026-11-25. Scope: six public-information and fixed-recipient correspondence tools, implemented by the WordPress plugin. This is a source and targeted regression review, not an independent penetration test or a security certification.

## Authorization change

Version 0.3.0 removes the `approved` argument and the `human_approval_required` preparation assertion. Each client governs its own authorization. Strict argument validation rejects obsolete `approved` values, including `false`, so a stale client cannot accidentally turn a refusal into transmission. Clients must refresh discovery and prepare a new ticket.

The ticket still binds the exact reply address, subject, body, expiry and nonce to the configured destination. It establishes draft integrity, not identity or human consent. The signing key and existing quota namespaces are preserved across this release. Ticket payloads and receipt identifiers use the v0.3.0 domain, invalidating older prepared tickets.

## Findings addressed

| Finding | Impact within scope | v0.3.0 change | Evidence |
| --- | --- | --- | --- |
| Unexpected mail exceptions were recorded as definite failures. | A WordPress hook can throw after mail-service acceptance, making replacement sends risky. | Preserve `pending_unknown`, the original receipt and the single-use reservation; discourage automatic resend. Count the initial uncertain handoff as `mail_uncertain`, separately from a blocked replay. | `tests/v030-test.php` injects exceptions before and after simulated acceptance and checks their telemetry classification; `tests/v030-wordpress.php` exercises the real post-send hook with an in-memory transport. |
| Outcome-recording exceptions could erase a known handoff response. | Clients lost the useful result and receipt after a database failure. | Preserve the known result and receipt with `outcome_recorded: false`; finalization detects failed writes and only changes pending reservations. | Failure injection and conditional-state checks in `tests/v030-test.php`. |
| Quota SQL failures were indistinguishable from exhausted capacity. | Mail remained blocked, but clients and operational counters received the wrong reason. | Distinguish `storage_unavailable` from `rate_limited`, retaining the existing limits, ordering and fail-closed behavior. | Nine injected SQL-failure stages, actual exhaustion checks and retained-reservation checks; existing real database and concurrency fixtures. |
| Preparation and handoff used different reply-address validators. | Some valid-looking prepared drafts consumed quota but failed before handoff. | Use one validator for the unverified body-only reply address; retain WordPress validation for the configured delivery destination. | Real WordPress/PHP divergence reproduced for quoted local parts and IP-literal domains, followed by successful simulated fixed-recipient handoff. |
| Unsupported-method responses advertised unsupported methods. | HTTP clients received an inaccurate `Allow` header. | Return `Allow: POST`, including the late WordPress response boundary. | Isolated server check and actual WordPress HTTP response check. |

Implementation: [Server.php](../src/Server.php), [WordPressBackend.php](../src/WordPressBackend.php), [WordPress adapter](../ontogeny-mcp.php).

## Controls retained

Only the configured destination receives correspondence. Callers cannot select recipients, add attachments, issue arbitrary network requests or read private implementation state. Tickets expire after ten minutes, and an atomic reservation permits at most one handoff for a given ticket. Replay remains blocked after success, explicit failure or uncertainty.

The request boundary retains TLS, canonical authority, Origin, REST-modifier, JSON and size checks. Reply addresses remain unverified body text, outside mail headers. Aggregate telemetry stores fixed categories rather than draft contents, raw addresses, raw IPs or exception messages. Accepted, failed and uncertain handoff outcomes are counted individually; other events are sampled. Source and address quota identifiers remain pseudonymous and stable across ticket-key rotation.

Limits bound row creation per time window; scheduled expiry cleanup is required to bound retained history. Correspondence itself remains in the mail system, outside these counter-table privacy claims.

## Residual limitations and useful next steps

**Anonymous capacity exhaustion:** enough sources and claimed addresses can consume the shared mail allowance. This causes temporary loss of the MCP contact channel. The fixed destination prevents an arbitrary-recipient relay. Keep the contact-page fallback and inspect denial counters before changing limits.

**Conservative source sharing:** shared proxies, IPv6 /64 grouping and 256 keyed source slots can make unrelated callers share capacity. Increase slot counts or revise deployment source handling only when observed traffic justifies the storage and operational tradeoff. Forwarded headers supplied by callers remain untrusted.

**Hosting boundary:** application admission occurs after the web server and PHP have accepted work. Hosting-layer traffic protection and correct source/TLS metadata remain necessary. A separate MCP hostname is optional for this six-tool service; it is not itself an additional protection.

**Mail uncertainty:** the service reports its mail-function result and maintains replay protection, but cannot prove inbox delivery. External mail plugins and hooks remain within the trusted WordPress deployment. A lost response or an uncertain result must not trigger an automatic replacement send with a new ticket. Exactly-once inbox delivery is not claimed.

**Scope discipline:** registration, OAuth, verification mail, arbitrary recipients, attachments, message archives and new resources/prompts are not required to strengthen the present public-information/contact role. Add them only for an explicit new use case.

## Verification

- 176 existing isolated checks passed: protocol, configuration, storage adapter, source/admission quotas, transport boundaries, structured results, signing-key lifecycle and telemetry.
- 42 focused v0.3.0 checks passed, with simulated mail and database fault injection.
- 24 real database state/admission checks passed using temporary tables in the isolated WordPress lab.
- Four real database concurrency phases passed: one handoff reservation for six same-ticket workers, five of six source admissions, one of six admissions against the last global slot, and ten of twelve preparation admissions. The dedicated fixture table was removed afterward.
- Seven additional WordPress checks passed with an in-memory PHPMailer transport; no external mail was sent.
- 17 WordPress HTTP boundary checks passed over loopback with simulated TLS, including the corrected `Allow` header.

The database fixtures ran through a guarded PHP CLI bootstrap of WordPress, supplying their CLI fixture marker. Live delivery testing from v0.2.1 is historical evidence; it is not claimed as a new v0.3.0 inbox-delivery test.

## Primary references

- [MCP tools and interaction model](https://modelcontextprotocol.io/specification/2025-11-25/server/tools)
- [MCP transport authorization](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization)
- [MCP Streamable HTTP](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports)
- [WordPress `wp_mail()`](https://developer.wordpress.org/reference/functions/wp_mail/)
- [WordPress `is_email()`](https://developer.wordpress.org/reference/functions/is_email/)
- [WordPress `rest_send_allow_header()`](https://developer.wordpress.org/reference/functions/rest_send_allow_header/)
