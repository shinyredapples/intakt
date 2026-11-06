# [Codex] A turn that never completed is returned as a complete answer when not streaming

Draft, not posted. Versions: `symfony/ai-codex-platform` v1.0.0-BETA1, `symfony/ai-platform` v1.0.0-BETA1, `symfony/process` v8.1.9, PHP 8.5.12.

## What happens

`codex exec --json` ends a finished turn with a `turn.completed` event, which also carries the usage. `RawProcessResult::getData()` returns the last `agent_message` whether or not that event arrived, so a stream that was cut off after a message reads as a complete answer, without usage; the streamed path yields the text and ends without an error the same way. A real `codex exec` exits with 1 on failed and interrupted turns, and the bridge already throws there; the case left is an exit with 0 and no `turn.completed`, when the CLI's in-process event channel closes before the turn ends. `reproduce.php` stands in for it with `printf` (exit 0), converts output that stops after the agent message, once plain and once streamed, and prints:

```
not streamed: Symfony\AI\Platform\Result\TextResult: "{"verdict":"approve"}"
A turn that never completed passes for a complete answer
streamed: "{"verdict":"approve"}"
A streamed turn that never completed ends without an error
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
- **Pull request search (D12, 2026-11-03, `main` 17d320e7beee):** open with the prefix `[Platform][Codex]`: none; #2713 carries `[Platform][ClaudeCode][Codex]` (`Parse the JSON Lines of the CLI output on any platform`); open touching `src/platform/src/Bridge/Codex/`: #2713 and #2719 (no prefix, HTTP options); merged since BETA1 on that path: none.
- **Fix pull request:** `issue only`. The change breaks backward compatibility: the bridge's converter tests hand it a bare `agent_message` without `turn.completed` and expect a `TextResult`, and the streamed path needs the same rule, so the maintainers should choose where the check lives. Open pull requests (checked 2026-11-03 against `main` 17d320e7beee): none fixes it; #2713 and #2719 edit the bridge's `RawProcessResult` and `ModelClient` for other reasons.
- **Owner review (2026-11-03, `main` 17d320e7):** an issue, medium confidence. `printf` exits 0, while a real `codex exec` exits 1 on failed and interrupted turns; the issue names the exit-0 path without `turn.completed` and answers the objection from #2169 that Codex was left out on purpose. `ai-codex-platform-usage-without-answer` is one sentence of it. `reproduce.php` gained the streamed case (fails at the pinned versions).
- **Refresh (2026-11-06, `main` 6747a33):** still fails; `RawProcessResult::getData()` and `ResultConverter::convertStream()` still check for neither `turn.completed` nor `turn.failed` (the only change to the bridge since 17d320e7 is 642cd818, which rejects tool definitions). The exit-0 path is shown in the Codex CLI source (`openai/codex` 4688e493, `codex-rs/exec/src/lib.rs`): the event loop breaks when the in-process event channel returns `None`, and `run_main()` exits with 1 only when `error_seen` was set by an `error` notification or a failed or interrupted `turn/completed`. `ISSUE.md` links both places and names symfony/ai#2712 (open issue, JSON Lines split on `PHP_EOL` on Windows) and #2713 (open pull request fixing it) as related: the same files, other bugs.
- **Status:** `recheck needed before filing`: the refresh above names no newest release and no duplicate search, so the report has no "Checked" line; the owner records the dated check against upstream first (see `upstream/README.md`). The issue text is `ISSUE.md`.
