# [Agent] A streamed execution cut mid-text reports no token usage, although its rounds were billed

Draft, not posted. Versions: `symfony/ai-agent` v1.0.0-BETA1, `symfony/ai-platform` v1.0.0-BETA1, PHP 8.5.12.

## What happens

An agent with a toolbox streams its answer (`stream` option). Round one streams a tool call and its usage (110 tokens); the tool runs; round two streams its usage (120 tokens) and half a sentence, then the stream ends with `IncompleteStreamException`. The execution fails, the tool ran once, and each round's `StreamResult` holds its own usage once. But `Execution::getMetadata()` holds no `token_usage` at all: `Runner::run()` writes the aggregated metadata onto the final result only, and `Execution::consume()` merges only the final `Result` update, which a failed execution never yields. The docblock of `Execution::getMetadata()` says the streamed metadata is "populated while the deltas are consumed, mirroring a StreamResult", and a cut `StreamResult` keeps the usage it read (its `ErrorEvent` merges listener metadata before the rethrow).

`StreamCutAfterToolCallTest.php` drives the agent through `MockPlatformFactory` with a `ScriptedResponse` closure that returns the two `StreamResult`s, and records each round's `DeferredResult` through the platform's `ResultEvent`. Run alone (`vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php upstream/ai-agent-streamed-tool-call-cut-stream`):

```
 ✔ The execution fails when the stream is cut mid text
 ✔ The tool runs once
 ✔ Each round reports its usage once
 ✔ A complete execution counts the usage of its rounds once
 ✘ The failed execution counts the usage of its rounds once
   │ Failed asserting that 0 is identical to 230.
```

The fourth test is the control: the same run without the cut reports 230 tokens (110 and 120) once.

## Expected

The failed execution's metadata holds the usage of every round it read, each once: 230 tokens. A caller that bills an agent turn from `Execution::getMetadata()` otherwise bills nothing for a cut run.

## Effect in Intakt

None today. Intakt does not read the usage from the execution: `TierRoutedPlatform` keeps each round's `DeferredResult`, and `UsageLedger` leaves a cut call `unsettled` with the usage of each round, read from the round's own `StreamResult` (`docs/architecture/vendor.md`). Settling that usage once is plan T25's next part.

## Upstream status

- **Pinned versions:** still fails (one of five tests, as above).
- **Development branch:** still fails, `0 is identical to 230`. Run in a scratch copy of `vendor/` with `src/agent` and `src/platform` of `symfony/ai` `main` e2b5e8476ead swapped in. At the head of the open symfony/ai#2617 (4584b4986628, `src/agent` and `src/platform` swapped in), which yields each round's result as a `ModelResult` update: `110 is identical to 230`. The tool round is counted, the cut round is not, because the cut round never reaches its `ModelResult`.
- **Checked:** 2026-11-12 against `symfony/ai` `main` e2b5e8476ead and the newest release v1.0.0-BETA1 (f620d0fc6790); duplicate search in `symfony/ai` issues and pull requests, open and closed, with the terms `Execution metadata token usage`, `IncompleteStreamException agent`, `token usage stream error`, `agent stream usage failed`. Found: #2617 (open pull request, each round's usage on the execution as it happens, for canceled and abandoned runs; not a run that fails), #2360 and #2363 (closed, stream listeners notified on a mid-stream error so a `StreamResult` keeps its usage; the platform side, done), #2898 (open issue, a mid-stream transport error re-sends the request; another bug), #2342 (open RFC, execution traces).
- **Form:** a comment on the open #2617, which already works on the bug and needs one more case; its text is `COMMENT.md`. Every `symfony/ai` pull request targets `main` (`CONTRIBUTING.md`).
- **Decision:** `ready to file` as a comment on symfony/ai#2617.
