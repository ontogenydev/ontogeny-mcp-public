# Changelog

## 0.1.1 — inaugural GPL release

- Replace the aggregate credential index with authoritative independent records.
- Deny stale-cache authorization, expose legacy orphan records for revocation, and condition expiry cleanup on unchanged record state.
- Fail closed on credential storage errors and report administrative failure accurately.
- Keep recipient configuration outside source control; restricted sending is disabled without valid operator configuration.
- Retain the six-tool contract, signing domain, reservation/quota behavior and mail handoff semantics.
- License the reviewed public distribution under GPL-2.0-or-later.

## 0.1.0 — public reference snapshot

- Six original MCP tools and the unchanged v0.1.0 protocol core.
- Externalized private recipient configuration; restricted sending disabled until configured.
- Isolated protocol and configuration regression tests.
- Public documentation, configuration example, and release procedure.

This snapshot is derived from the deployed pilot, not a claim of byte-identical production source. No production change is implied. Live MCP interoperability and MCP-originated correspondence delivery remain unverified.
