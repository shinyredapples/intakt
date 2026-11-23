# [Codex] Tools the CLI already ran come back as tool calls for the caller

Filed upstream as a pull request, together with the Claude Code tool calls: https://github.com/symfony/ai/pull/2875. Versions: `symfony/ai-codex-platform` v1.0.0-BETA1, `symfony/ai-platform` v1.0.0-BETA1, `symfony/process` v8.1.9, PHP 8.5.12.

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

Checked on 2026-11-02; filed on 2026-11-03.

- **Pinned versions:** still fails. `php bin/console intakt:upstream:run` at `symfony/ai-*` v1.0.0-BETA1: `still fails  ai-codex-platform-cli-tool-calls`.
- **Development branch:** still fails. The same run in a scratch copy with every `symfony/ai-*` package at `main` (`symfony/ai-codex-platform` 937734bb71b9, `symfony/ai-platform` 17c452fa350f): `still fails  ai-codex-platform-cli-tool-calls`.
- **Existing issue or pull request:** none found. Candidates in `symfony/ai`, none the same bug: #2015 (closed pull request, adds tool-call trace metadata from the same `item.started` and `item.completed` events), #1826 (closed pull request that added the bridge), #2712 and #2713 (open, JSON Lines split on `PHP_EOL` in `RawProcessResult`).
- **Searches** (`symfony/ai` issues and pull requests, open and closed): `Codex tool_calls`, `Codex item.completed`, `label:Platform Codex`, `RawProcessResult`.
- **Decision:** `filed as pull request`: https://github.com/symfony/ai/pull/2875 (opened 2026-11-03 from the owner's fork, one signed commit, the "BC Break" label requested), one pull request for this bug and `ai-claude-code-platform-cli-tool-calls`.
- **Pull request search (D12, 2026-11-03, `main` 17d320e7beee):** open with the prefix `[Platform][Codex]`: none; #2713 carries `[Platform][ClaudeCode][Codex]` (`Parse the JSON Lines of the CLI output on any platform`); open touching `src/platform/src/Bridge/Codex/`: #2713 and #2719 (no prefix, HTTP options); merged since BETA1 on that path: none.
- **Fix pull request:** filed as symfony/ai#2875, by the owner instead of the combined issue. It changes the result `ResultConverterTest::testConvertReturnsMultiPartResultWithToolCalls` pins (#2015 added the trace on purpose), so it asks for the "BC Break" label. Before filing, `issue only` was the decision, for that reason. Open pull requests (checked 2026-11-03 against `main` 17d320e7beee): none fixes it; #2713 edits `RawProcessResult` only to split lines on any line ending, and #2719 edits `ModelClient`.
- **Owner review (2026-11-03, `main` 17d320e7):** "the CLI read one file" is now "the CLI called one MCP tool": `command_execution` items never reach the caller, and Codex streaming emits no `ToolCallComplete`. This bug and `ai-claude-code-platform-cli-tool-calls` go upstream as one combined issue, which the owner session drafts (superseded by symfony/ai#2875); there is no issue text in this folder.
- **Found by the pull request:** real CLI recordings (Claude Code 2.1.316, Codex CLI 0.166.0) show more than this report: an agent without application tools ends in `ToolNotFoundException` after the CLI answered; with a `FaultTolerantToolbox` the agent restarts the CLI until `maxToolCalls`; the Claude Code stream loses the answer; and the Claude Code bridge reported each call twice.
- **Related pull request:** https://github.com/symfony/ai/pull/2876. An agent with a toolbox made both bridges pass `--tools Array` or `--allowedTools Array`; with the pull request the bridges throw `MissingModelSupportException::forToolCalling()` instead, and the Codex `tools` and `allowed_tools` options throw, since `codex exec` has no flag that restricts tools. The pull request carries its own tests; there is no reproduction folder for it.
- **Intakt's workaround:** `CompletedCodexTurn` stays until a Symfony AI release contains #2875. With the fix applied, the bridge still returns a `TextResult` through it (checked), and it stays needed because Intakt accepts only a `TextResult`.
- **Status:** `filed as pull request` symfony/ai#2875, together with `ai-claude-code-platform-cli-tool-calls`; the next step is the release that contains it. Checked 2026-11-22: no `symfony/ai` release after v1.0.0-BETA1 yet (the newest tag is still v1.0.0-BETA1).
