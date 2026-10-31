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
