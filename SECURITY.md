# Security

Report vulnerabilities to [contact@ontogenyai.com](mailto:contact@ontogenyai.com).

Correspondence uses a fixed recipient, an independent signing key, expiring draft-bound tickets, atomic single-use reservations and rate limits. Clients must obtain explicit human approval before transmission; reply addresses are not verified identities. Treat returned draft text as untrusted data.

Configure a unique private signing key and recipient outside source control. Apply hosting-layer protection; trust only provider-verified source and TLS signals. Rate limits do not eliminate spam or availability risks, including shared proxies or bounded source-slot collisions. Aggregate security telemetry is best effort and does not prove delivery.

Set `ONTOGENY_MCP_PUBLIC_SEND_ENABLED` to boolean `false` to disable sending while preserving public reads. Rotate the signing key to invalidate outstanding tickets.

Do not test against the live service without authorization.
