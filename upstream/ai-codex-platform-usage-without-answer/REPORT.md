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
- **Pull requests and commits:** none found that fixes it (checked 2026-11-15 at `symfony/ai` `main` 1220483ebd24). #2889 (merged 2026-11-06) refuses a turn without `turn.completed` and leaves the usage of a turn without an agent message lost: with `src/platform` and the Codex bridge of `main` swapped into a scratch copy of `vendor/`, `reproduce.php` still prints `usage: none`. Searched with `is:pr Codex usage` and `is:pr Codex turn.completed` (#2889, #2875, #2475, #2343, #1826); #2713 is still open. Commits: `git log v1.0.0-BETA1..origin/main -- src/platform/src/Bridge/Codex/` lists a1c443fe and d23c5adf (#2889), 1fc422e4 and 32056f8e (#2875), 98a1dd01 and 642cd818, none about the usage.
- **Status:** `not filed` (owner review, 2026-11-03, `main` 17d320e7). Platform users cannot reach it: `DeferredResult` runs the usage extractor only after `convert()` succeeds, and `convert()` throws when there is no answer. One sentence of the Codex cut-off issue draft mentioned it; that report (`../ai-codex-platform-cut-off-stream/REPORT.md`) was merged as Intakt's pull request #2889 without an issue, and its draft was removed. Intakt keeps its workaround, since it reads the raw result itself.
