# [MCP SDK] Three parallel `tools/call` on one session

A probe, not a bug report. Versions: `mcp/sdk` v0.9.0, PHP 8.5.12.

## What is checked

`ParallelToolsCallTest.php` runs three `tools/call` requests of one session in three processes (`worker.php`) against one file session store. The interleaving is forced: without the lock, a tool waits until all three are inside it.

- With and without `session.lock`, with the protocol version left open and pinned to 2025-11-25, each call gets one response and its own progress notifications.
- With the lock, the calls of one session run one after the other; without it, they run at the same time.
- A tool without parameters lists them as an empty object, and a call with empty arguments reaches the tool as no arguments that encode as an empty object.

## Upstream status

- **Pinned versions:** passes. `php bin/console intakt:upstream:run` at `mcp/sdk` v0.9.0 (2026-11-20): `passes       mcp-parallel-tools-call-one-session`.
- **Development branch:** not run; nothing to compare, since the release already behaves as expected.
- **Pull requests and commits:** none needed: the probe found no bug (2026-11-20).
- **Decision:** passes, nothing to file (recorded 2026-11-20).
