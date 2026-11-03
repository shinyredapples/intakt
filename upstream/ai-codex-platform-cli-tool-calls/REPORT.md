# [Codex] Tools the CLI already ran come back as tool calls for the caller

Draft, not posted. Versions: `symfony/ai-codex-platform` v1.0.0-BETA1, `symfony/ai-platform` v1.0.0-BETA1, `symfony/process` v8.1.9, PHP 8.5.12.

## What happens

The Codex CLI runs its tools itself (MCP tools included) and reports each use as `item.started` and `item.completed` events before its final agent message. `RawProcessResult::getData()` collects them into `tool_calls`, and `ResultConverter` returns them as a `ToolCallResult` beside the final text. An `Agent` then treats them as calls to its own toolbox and sends the model another round, although the CLI already finished. Only tool items reach the caller (`RawProcessResult::extractToolCalls()` keeps an item whose type contains `tool`, a `function_call` or `mcp_call`, or one with a tool name): a `command_execution` item is ignored, and a streamed turn yields no `ToolCallComplete`, so the bug is limited to the MCP and function tools the CLI called, without streaming. `reproduce.php` converts a turn in which the CLI called one MCP tool and prints:

```
Symfony\AI\Platform\Result\MultiPartResult
The tool call the CLI already ran is handed to the caller to run again
```

## Expected

A `TextResult` with the final agent message, since no tool call is left for the caller to run.

## Effect in Intakt

`src/Core/Ai/Infrastructure/CompletedCodexTurn.php` drops `tool_calls` from the raw result before the converter sees it.

## Upstream status

Checked on 2026-11-02.

- **Pinned versions:** still fails. `php bin/console intakt:upstream:run` at `symfony/ai-*` v1.0.0-BETA1: `still fails  ai-codex-platform-cli-tool-calls`.
- **Development branch:** still fails. The same run in a scratch copy with every `symfony/ai-*` package at `main` (`symfony/ai-codex-platform` 937734bb71b9, `symfony/ai-platform` 17c452fa350f): `still fails  ai-codex-platform-cli-tool-calls`.
- **Existing issue or pull request:** none found. Candidates in `symfony/ai`, none the same bug: #2015 (closed pull request, adds tool-call trace metadata from the same `item.started` and `item.completed` events), #1826 (closed pull request that added the bridge), #2712 and #2713 (open, JSON Lines split on `PHP_EOL` in `RawProcessResult`).
- **Searches** (`symfony/ai` issues and pull requests, open and closed): `Codex tool_calls`, `Codex item.completed`, `label:Platform Codex`, `RawProcessResult`.
- **Decision:** `ready to file`.
- **Pull request search (D12, 2026-11-03, `main` 17d320e7beee):** open with the prefix `[Platform][Codex]`: none; #2713 carries `[Platform][ClaudeCode][Codex]` (`Parse the JSON Lines of the CLI output on any platform`); open touching `src/platform/src/Bridge/Codex/`: #2713 and #2719 (no prefix, HTTP options); merged since BETA1 on that path: none.
- **Fix pull request:** `issue only`. Whether the caller should see the tool uses the CLI ran itself is not beyond doubt: #2015 added the trace on purpose, and `ResultConverterTest::testConvertReturnsMultiPartResultWithToolCalls` pins the `MultiPartResult`. Open pull requests (checked 2026-11-03 against `main` 17d320e7beee): none fixes it; #2713 edits `RawProcessResult` only to split lines on any line ending, and #2719 edits `ModelClient`.
- **Owner review (2026-11-03, `main` 17d320e7):** "the CLI read one file" is now "the CLI called one MCP tool": `command_execution` items never reach the caller, and Codex streaming emits no `ToolCallComplete`. This bug and `ai-claude-code-platform-cli-tool-calls` go upstream as one combined issue, which the owner session drafts; there is no issue text in this folder.
- **Status:** `ready to file`, as one combined issue with `ai-claude-code-platform-cli-tool-calls` (owner drafts).
