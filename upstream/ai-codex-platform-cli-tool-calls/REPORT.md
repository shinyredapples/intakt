# [Codex] Tools the CLI already ran come back as tool calls for the caller

Draft, not posted. Versions: `symfony/ai-codex-platform` v1.0.0-BETA1, `symfony/ai-platform` v1.0.0-BETA1, `symfony/process` v8.1.9, PHP 8.5.12.

## What happens

The Codex CLI runs its tools itself (MCP tools included) and reports each use as `item.started` and `item.completed` events before its final agent message. `RawProcessResult::getData()` collects them into `tool_calls`, and `ResultConverter` returns them as a `ToolCallResult` beside the final text. An `Agent` then treats them as calls to its own toolbox and sends the model another round, although the CLI already finished. `reproduce.php` converts a turn in which the CLI read one file and prints:

```
Symfony\AI\Platform\Result\MultiPartResult
The tool call the CLI already ran is handed to the caller to run again
```

## Expected

A `TextResult` with the final agent message, since no tool call is left for the caller to run.

## Effect in Intakt

`src/Core/Ai/Infrastructure/CompletedCodexTurn.php` drops `tool_calls` from the raw result before the converter sees it.
