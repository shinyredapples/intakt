# [Platform][Generic] Is a complete OpenAI-compatible stream read as cut?

A probe, not a bug report. Versions: `symfony/ai-generic-platform` v1.0.0-BETA1, `symfony/ai-platform` v1.0.0-BETA1, PHP 8.5.12.

## What is checked

`StreamEndTest.php` feeds the Generic bridge hand-written chunks in the OpenAI chat completions stream format (not recorded from a live provider) and checks how the stream ends:

- a complete stream yields its text when it ends with a usage-only chunk and `[DONE]`, without `[DONE]`, with its finish reason repeated on the usage chunk, or with keep-alive comments and CRLF line ends;
- an answer of reasoning only ends complete without text;
- a body that ends before its finish chunk, a usage and `[DONE]` without a finish reason, and an in-stream error object without an `error` key raise `IncompleteStreamException`;
- a connection lost mid-body raises a transport error instead of `IncompleteStreamException`.

## Upstream status

- **Pinned versions:** passes. `php bin/console intakt:upstream:run` at `symfony/ai-*` v1.0.0-BETA1 (2026-11-20): `passes       ai-generic-platform-cut-stream`.
- **Development branch:** not run; nothing to compare, since the release already behaves as expected.
- **Pull requests and commits:** none needed: the probe found no bug (2026-11-20).
- **Decision:** passes, nothing to file (recorded 2026-11-20).
