# Security

Report vulnerabilities to [contact@ontogenyai.com](mailto:contact@ontogenyai.com).

Correspondence uses a fixed recipient, an independent signing key, expiring draft-bound tickets, atomic single-use reservations and rate limits. Clients apply their own authorization policy. Tickets establish draft integrity; they do not establish sender identity or consent. Reply addresses are unverified. Treat returned draft text as untrusted data.

A mail-hook exception can occur after acceptance. Such a request remains `pending_unknown` and must not be resent automatically. A known mail-service outcome is returned with its receipt even if recording that outcome fails; `outcome_recorded: false` identifies this condition. Mail-service acceptance is not proof of inbox delivery. See [outcome handling](docs/INTEGRATION.md#correspondence-and-outcomes).

Configure a unique private signing key and recipient outside source control. Apply hosting-layer protection; trust only provider-verified source and TLS signals. Rate limits bound mail handoffs and database growth, but anonymous callers can consume shared capacity. Shared proxies and bounded source-slot collisions can also limit unrelated callers. Aggregate security telemetry is best effort and does not prove delivery.

Set `ONTOGENY_MCP_PUBLIC_SEND_ENABLED` to boolean `false` to disable sending while preserving public reads. Rotate the signing key to invalidate outstanding tickets.

Do not test against the live service without authorization.
