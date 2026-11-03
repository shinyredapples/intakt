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

## Upstream status

Checked on 2026-11-02.

- **Pinned versions:** still fails. `php bin/console intakt:upstream:run` at `symfony/ai-*` v1.0.0-BETA1: `still fails  ai-claude-code-platform-cli-tool-calls`.
- **Development branch:** still fails. The same run in a scratch copy with every `symfony/ai-*` package at `main` (`symfony/ai-claude-code-platform` 83819ce82d64, `symfony/ai-platform` 17c452fa350f): `still fails  ai-claude-code-platform-cli-tool-calls`.
- **Existing issue or pull request:** none found. Candidates in `symfony/ai`, none the same bug: #2015 (closed pull request, adds tool-call trace metadata from the same CLI events), #2063 (closed pull request, tool-use deltas in the stream), #2603 (closed pull request, restricts the CLI's tools with the `tools` option), #2712 and #2713 (open, JSON Lines split on `PHP_EOL` in `RawProcessResult`).
- **Searches** (`symfony/ai` issues and pull requests, open and closed): `ClaudeCode tool_calls`, `"Claude Code" tool_use`, `RawProcessResult`, `label:Platform ClaudeCode`.
- **Decision:** `ready to file`.
- **Fix pull request:** `issue only`. Whether the caller should see the tool uses the CLI ran itself is not beyond doubt: #2015 added them on purpose as trace metadata, and the bridge's own tests pin the `MultiPartResult`. Open pull requests (checked 2026-11-03 against `main` 17d320e7beee): none fixes it; #2713 edits `RawProcessResult` only to split lines on any line ending, and #2719 edits `ModelClient`.
