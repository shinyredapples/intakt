# [Claude Code] Tools the CLI already ran come back as tool calls for the caller

Filed upstream as a pull request, together with the Codex tool calls: https://github.com/symfony/ai/pull/2875. Versions: `symfony/ai-claude-code-platform` v1.0.0-BETA1, `symfony/ai-platform` v1.0.0-BETA1, PHP 8.5.12.

## What happens

The Claude Code CLI runs its built-in tools (`Read`, `Grep`, `Bash`, ...) itself and reports each use in its stream before its final result. `RawProcessResult::getData()` collects those `tool_use` blocks into `tool_calls`, and `ResultConverter` returns them as a `ToolCallResult` beside the final text. An `Agent` then treats them as calls to its own toolbox: it looks for a tool named `Read`, fails or runs a different tool of that name, and sends the model another round, although the CLI already finished.

The streaming path has the same bug. With `stream: true`, `ResultConverter` yields `ToolCallComplete` for the `tool_use` block of the CLI's first message, and the Agent's `Runner::consumeStream()` then takes it as a tool call for its own toolbox and skips every later delta of the round, so the text of the final answer is dropped.

`reproduce.php` converts a run in which the CLI read one file, once as a plain result and once as a stream, and prints:

```
not streamed: Symfony\AI\Platform\Result\MultiPartResult
The tool call the CLI already ran is handed to the caller to run again
streamed: Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart, Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta, Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete, TextDelta "The readme has a title."
The stream completes a tool call the CLI already ran, so an agent drops the text after it
```

## Expected

A `TextResult` with the CLI's final result, and a stream without `ToolCallComplete`, since no tool call is left for the caller to run.

## Effect in Intakt

`src/Core/Ai/Infrastructure/FinishedProcessResult.php` drops `tool_calls` from the raw result before the converter sees it.

## Upstream status

Checked on 2026-11-02; filed on 2026-11-03.

- **Pinned versions:** still fails. `php bin/console intakt:upstream:run` at `symfony/ai-*` v1.0.0-BETA1: `still fails  ai-claude-code-platform-cli-tool-calls`.
- **Development branch:** still fails. The same run in a scratch copy with every `symfony/ai-*` package at `main` (`symfony/ai-claude-code-platform` 83819ce82d64, `symfony/ai-platform` 17c452fa350f): `still fails  ai-claude-code-platform-cli-tool-calls`.
- **Existing issue or pull request:** none found. Candidates in `symfony/ai`, none the same bug: #2015 (closed pull request, adds tool-call trace metadata from the same CLI events), #2063 (closed pull request, tool-use deltas in the stream), #2603 (closed pull request, restricts the CLI's tools with the `tools` option), #2712 and #2713 (open, JSON Lines split on `PHP_EOL` in `RawProcessResult`).
- **Searches** (`symfony/ai` issues and pull requests, open and closed): `ClaudeCode tool_calls`, `"Claude Code" tool_use`, `RawProcessResult`, `label:Platform ClaudeCode`.
- **Decision:** `filed as pull request`: https://github.com/symfony/ai/pull/2875 (opened 2026-11-03 from the owner's fork, one signed commit, the "BC Break" label requested), one pull request for this bug and `ai-codex-platform-cli-tool-calls`.
- **Pull request search (D12, 2026-11-03, `main` 17d320e7beee):** open with the prefix `[Platform][ClaudeCode]`: #2713 (`[Platform][ClaudeCode][Codex] Parse the JSON Lines of the CLI output on any platform`); open touching `src/platform/src/Bridge/ClaudeCode/`: #2713 and #2719 (no prefix, HTTP options); merged since BETA1 on that path: none.
- **Fix pull request:** filed as symfony/ai#2875, by the owner instead of the combined issue. It changes the result the bridge's own tests pin (#2015 added the tool uses on purpose as trace metadata), so it asks for the "BC Break" label. Before filing, `issue only` was the decision, for that reason. Open pull requests (checked 2026-11-03 against `main` 17d320e7beee): none fixes it; #2713 edits `RawProcessResult` only to split lines on any line ending, and #2719 edits `ModelClient`.
- **Owner review (2026-11-03, `main` 17d320e7):** the streaming path has the same bug, so `reproduce.php` gained a `stream: true` case (fails at the pinned versions). This bug and `ai-codex-platform-cli-tool-calls` go upstream as one combined issue, which the owner session drafts (superseded by symfony/ai#2875); there is no issue text in this folder.
- **Found by the pull request:** real CLI recordings (Claude Code 2.1.316, Codex CLI 0.166.0) show more than this report: an agent without application tools ends in `ToolNotFoundException` after the CLI answered; with a `FaultTolerantToolbox` the agent restarts the CLI until `maxToolCalls`; the Claude Code stream loses the answer; and the Claude Code bridge reported each call twice.
- **Related pull request:** https://github.com/symfony/ai/pull/2876. An agent with a toolbox made both bridges pass `--tools Array` or `--allowedTools Array`; with the pull request the bridges throw `MissingModelSupportException::forToolCalling()` instead, and the Codex `tools` and `allowed_tools` options throw, since `codex exec` has no flag that restricts tools. The pull request carries its own tests; there is no reproduction folder for it.
- **Intakt's workaround:** `FinishedProcessResult` stays until a Symfony AI release contains #2875. With the fix applied, the bridge still returns a `TextResult` through it (checked), and it stays needed because Intakt accepts only a `TextResult`.
- **Status:** `filed as pull request` symfony/ai#2875, together with `ai-codex-platform-cli-tool-calls`; the next step is the release that contains it. Checked 2026-11-22: no `symfony/ai` release after v1.0.0-BETA1 yet (the newest tag is still v1.0.0-BETA1).
