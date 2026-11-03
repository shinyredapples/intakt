# [Codex] The usage of a turn without an agent message is lost

Draft, not posted. Versions: `symfony/ai-codex-platform` v1.0.0-BETA1, `symfony/ai-platform` v1.0.0-BETA1, `symfony/process` v8.1.9, PHP 8.5.12.

## What happens

`RawProcessResult::getData()` attaches the usage of `turn.completed` only to an agent message. When a completed turn has none (only reasoning, for example), it returns an empty array, so `TokenUsageExtractor` finds no usage and the tokens the account spent are not reported. `reproduce.php` extracts the usage of such a turn and prints:

```
usage: none
The usage of a turn without an agent message is lost
```

## Expected

The usage of `turn.completed` whatever the turn produced, while the converter still fails on the missing answer.

## Effect in Intakt

`src/Core/Ai/Infrastructure/CompletedCodexTurn.php` reads the usage of `turn.completed` from the process output when the bridge returned no agent message.

## Upstream status

Checked on 2026-11-02.

- **Pinned versions:** still fails. `php bin/console intakt:upstream:run` at `symfony/ai-*` v1.0.0-BETA1: `still fails  ai-codex-platform-usage-without-answer`.
- **Development branch:** still fails. The same run in a scratch copy with every `symfony/ai-*` package at `main` (`symfony/ai-codex-platform` 937734bb71b9, `symfony/ai-platform` 17c452fa350f): `still fails  ai-codex-platform-usage-without-answer`.
- **Existing issue or pull request:** none found. Candidates in `symfony/ai`, none the same bug: #2475 (closed pull request, usage of `turn.completed` for streamed results), #2625 (closed issue, usage summed twice in Generic chat-completions streams), #2343 (closed pull request, cached tokens for Mistral).
- **Searches** (`symfony/ai` issues and pull requests, open and closed): `Codex TokenUsageExtractor`, `Codex usage`, `turn.completed`, `label:Platform Codex`.
- **Decision:** `ready to file`.
