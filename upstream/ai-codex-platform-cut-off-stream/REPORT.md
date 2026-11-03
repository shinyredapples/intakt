# [Codex] A turn that never completed is returned as a complete answer when not streaming

Draft, not posted. Versions: `symfony/ai-codex-platform` v1.0.0-BETA1, `symfony/ai-platform` v1.0.0-BETA1, `symfony/process` v8.1.9, PHP 8.5.12.

## What happens

`codex exec --json` ends a finished turn with a `turn.completed` event, which also carries the usage. `RawProcessResult::getData()` returns the last `agent_message` whether or not that event arrived, so a stream that was cut off after a message reads as a complete answer, without usage. `reproduce.php` converts a stream that stops after the agent message and prints:

```
Symfony\AI\Platform\Result\TextResult: "{"verdict":"approve"}"
A turn that never completed passes for a complete answer
```

## Expected

`IncompleteStreamException` when the output holds no `turn.completed` (or `turn.failed`) event, so a caller can tell a cut-off turn from a finished one.

## Effect in Intakt

`src/Core/Ai/Infrastructure/CompletedCodexTurn.php` wraps the bridge's raw result and raises `IncompleteStreamException` when an agent message comes without the usage of `turn.completed`.

## Upstream status

Checked on 2026-11-02.

- **Pinned versions:** still fails. `php bin/console intakt:upstream:run` at `symfony/ai-*` v1.0.0-BETA1: `still fails  ai-codex-platform-cut-off-stream`.
- **Development branch:** still fails. The same run in a scratch copy with every `symfony/ai-*` package at `main` (`symfony/ai-codex-platform` 937734bb71b9, `symfony/ai-platform` 17c452fa350f): `still fails  ai-codex-platform-cut-off-stream`.
- **Existing issue or pull request:** none found. Candidates in `symfony/ai`, none the same bug: #2475 (closed pull request, adds the usage of `turn.completed` to streamed results only), #2579 (open question about a commentary channel), #1826 (closed pull request that added the bridge).
- **Searches** (`symfony/ai` issues and pull requests, open and closed): `turn.completed`, `Codex agent_message`, `label:Platform Codex`, `IncompleteStreamException`.
- **Decision:** `ready to file`.
