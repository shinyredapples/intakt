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
- **Pull request search (D12, 2026-11-03, `main` 17d320e7beee):** open with the prefix `[Platform][Codex]`: none; #2713 carries `[Platform][ClaudeCode][Codex]` (`Parse the JSON Lines of the CLI output on any platform`); open touching `src/platform/src/Bridge/Codex/`: #2713 and #2719 (no prefix, HTTP options); merged since BETA1 on that path: none.
- **Fix pull request:** `issue only`. The expected behavior is in doubt: the converter must still fail on a turn without an answer, and it throws before the usage extractor runs, so the usage is reachable only for a caller that handles that failure itself. Open pull requests (checked 2026-11-03 against `main` 17d320e7beee): none fixes it; #2713 and #2719 edit the bridge's `RawProcessResult` and `ModelClient` for other reasons.
- **Refresh (2026-11-06, `main` 6747a33):** still fails; `RawProcessResult::getData()` still attaches the usage only to an agent message. Related, other bugs in the same files: symfony/ai#2712 and #2713 (JSON Lines split on `PHP_EOL`).
- **Status:** `not filed` (owner review, 2026-11-03, `main` 17d320e7). Platform users cannot reach it: `DeferredResult` runs the usage extractor only after `convert()` succeeds, and `convert()` throws when there is no answer. One sentence of the Codex cut-off issue (`../ai-codex-platform-cut-off-stream/ISSUE.md`) mentions it. Intakt keeps its workaround, since it reads the raw result itself.
