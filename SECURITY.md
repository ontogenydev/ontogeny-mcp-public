# Security

Report vulnerabilities to [contact@ontogenyai.com](mailto:contact@ontogenyai.com).

Correspondence uses a fixed recipient, expiring draft-bound tickets, single-use reservations and rate limits. Clients must obtain human approval before transmission; reply addresses are not verified identities.

Deployers should configure their own recipient, keep deployment configuration out of source control, and monitor abuse. Rate limits do not eliminate spam or quota-exhaustion risks. Apply hosting-layer protection as appropriate. Set `ONTOGENY_MCP_PUBLIC_SEND_ENABLED` to boolean `false` to disable sending while preserving public reads.

Do not test against the live service without authorization.
