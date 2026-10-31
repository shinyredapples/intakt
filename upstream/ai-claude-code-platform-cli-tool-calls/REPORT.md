# [Claude Code] Tools the CLI already ran come back as tool calls for the caller

Draft, not posted. Versions: `symfony/ai-claude-code-platform` v1.0.0-BETA1, `symfony/ai-platform` v1.0.0-BETA1, PHP 8.5.12.

## What happens

The Claude Code CLI runs its built-in tools (`Read`, `Grep`, `Bash`, ...) itself and reports each use in its stream before its final result. `RawProcessResult::getData()` collects those `tool_use` blocks into `tool_calls`, and `ResultConverter` returns them as a `ToolCallResult` beside the final text. An `Agent` then treats them as calls to its own toolbox: it looks for a tool named `Read`, fails or runs a different tool of that name, and sends the model another round, although the CLI already finished. `reproduce.php` converts a run in which the CLI read one file and prints:

```
Symfony\AI\Platform\Result\MultiPartResult
The tool call the CLI already ran is handed to the caller to run again
```

## Expected

A `TextResult` with the CLI's final result, since no tool call is left for the caller to run.

## Effect in Intakt

`src/Core/Ai/Infrastructure/FinishedProcessResult.php` drops `tool_calls` from the raw result before the converter sees it.
