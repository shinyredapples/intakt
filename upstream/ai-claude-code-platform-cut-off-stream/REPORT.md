# [Claude Code] A cut-off run raises a generic error instead of `IncompleteStreamException` when not streaming

Draft, not posted. Versions: `symfony/ai-claude-code-platform` v1.0.0-BETA1, `symfony/ai-platform` v1.0.0-BETA1, `symfony/process` v8.1.9, PHP 8.5.12.

## What happens

With `stream: true`, `ResultConverter` throws `IncompleteStreamException` when the CLI's output ends before `message_stop`. Without streaming, the same cut-off run is reported three different ways, none of them `IncompleteStreamException`. `reproduce.php` feeds a run that stops after `message_start` to `ResultConverter::convert()` and prints:

```
the CLI exits 0: Symfony\AI\Platform\Exception\RuntimeException "Claude Code CLI did not return any result."
the CLI exits 143: Symfony\AI\Platform\Exception\RuntimeException "Claude Code CLI process failed: "exit code 143""
the CLI is killed: Symfony\Component\Process\Exception\ProcessSignaledException "The process has been signaled with signal "15"."
```

A run that times out (`ModelClient`'s `timeout`) raises `ProcessTimedOutException` from `RawProcessResult::getData()` in the same way.

## Expected

`IncompleteStreamException` whenever the output holds no final `type=result` event, whatever the exit, so a caller can tell a run that was cut off from one the CLI reported as failed (a `type=result` event with `is_error` or `errors`).

## Effect in Intakt

`src/Core/Ai/Infrastructure/FinishedProcessResult.php` wraps the bridge's raw result and turns each of these cases into `IncompleteStreamException` when the output has no final result event.

## Upstream status

Checked on 2026-11-02.

- **Pinned versions:** still fails. `php bin/console intakt:upstream:run` at `symfony/ai-*` v1.0.0-BETA1: `still fails  ai-claude-code-platform-cut-off-stream`.
- **Development branch:** still fails. The same run in a scratch copy with every `symfony/ai-*` package at `main` (`symfony/ai-claude-code-platform` 83819ce82d64, `symfony/ai-platform` 17c452fa350f): `still fails  ai-claude-code-platform-cut-off-stream`, with the same three outcomes.
- **Existing issue or pull request:** none found. Candidates in `symfony/ai`, none the same bug: #2169 (closed pull request, `IncompleteStreamException` in more bridges, streaming only), #2360 (closed issue, stream listeners miss the completion when a stream throws), #2063 (closed pull request, tool-use deltas in the Claude Code stream).
- **Searches** (`symfony/ai` issues and pull requests, open and closed): `IncompleteStreamException`, `"did not return any result"`, `ClaudeCode ResultConverter`, `label:Platform ClaudeCode`.
- **Decision:** `ready to file`.
