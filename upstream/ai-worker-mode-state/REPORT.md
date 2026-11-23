# [AI Bundle][MCP Bundle] Worker-mode state of two models and two MCP sessions in one kernel

A probe, not a bug report. Versions: `symfony/ai-bundle` v1.0.0-BETA1, `symfony/ai-generic-platform` v1.0.0-BETA1, `symfony/mcp-bundle` v1.0.0-BETA1, `mcp/sdk` v0.9.0, `symfony/http-kernel` v8.2.0-BETA2, PHP 8.5.12.

## What is checked

`WorkerModeStateTest.php` boots one probe kernel with the bundles Intakt runs in its worker (a Generic platform, two MCP servers with file sessions and the session lock) and sends each request through it, terminating it in between as a FrankenPHP worker does:

- a second request never sees the first request's model;
- a plain request after a structured one gets text, not the earlier object;
- a second MCP session never lists the first session's tools, and a session of one server is refused by the other;
- memory stays flat over 1,000 alternating calls.

## Upstream status

- **Pinned versions:** passes. `php bin/console intakt:upstream:run` at `symfony/ai-*` v1.0.0-BETA1 and `mcp/sdk` v0.9.0 (2026-11-20): `passes       ai-worker-mode-state`.
- **Development branch:** not run; nothing to compare, since the release already behaves as expected.
- **Pull requests and commits:** none needed: the probe found no bug (2026-11-20).
- **Decision:** passes, nothing to file (recorded 2026-11-20).
