# [Platform] Structured output with enums and nested lists, per provider

A probe, not a bug report. Versions: `symfony/ai-platform` v1.0.0-BETA1, `symfony/ai-anthropic-platform` v1.0.0-BETA1, `symfony/ai-open-ai-platform` v1.0.0-BETA1, `symfony/ai-generic-platform` v1.0.0-BETA1, PHP 8.5.12.

## What is checked

`StructuredOutputTest.php` replays each answer from a redacted cassette in `cassettes/` (the request must match the recorded one; no network, no credentials) for Anthropic (`claude-haiku-4-5`), OpenAI (`gpt-4o`) and the Generic bridge (`local-model`):

- an enum and a nested list fill the typed object;
- a closed stream of the whole answer fills the typed object;
- a stream stopped by the token limit mid-array throws a typed exception and returns no object (`MaxOutputTokensException` for Anthropic and OpenAI; for the Generic bridge, which raises that only for a cut tool call, the structured-output decoder refuses the broken JSON with a `RuntimeException`).

## Upstream status

- **Pinned versions:** passes. `php bin/console intakt:upstream:run` at `symfony/ai-*` v1.0.0-BETA1 (2026-11-20): `passes       ai-structured-output-enums-nested-lists`.
- **Development branch:** not run; nothing to compare, since the release already behaves as expected.
- **Pull requests and commits:** none needed: the probe found no bug (2026-11-20).
- **Decision:** passes, nothing to file (recorded 2026-11-20).
