# [Agent] A streamed execution cut mid-text reports no token usage, although its rounds were billed

Filed as a comment on symfony/ai#2617 on 2026-11-14. Versions: `symfony/ai-agent` v1.0.0-BETA1, `symfony/ai-platform` v1.0.0-BETA1, PHP 8.5.12.

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

None today. Intakt does not read the usage from the execution: `TierRoutedPlatform` keeps each round's `DeferredResult`, and `UsageLedger` leaves a cut call `unsettled` with the usage of each round, read from the round's own `StreamResult` (`docs/architecture/vendor.md`). A scripted run of that case (a tool round, then a stream cut mid-text) leaves one `unsettled` row holding both rounds' usage and blocks the review (`tests/Plugin/SymfonyAiVendor/SymfonyAiVendorTest.php`).

## Upstream status

- **Pinned versions:** still fails (one of five tests, as above).
- **Development branch:** still fails, `0 is identical to 230`. Run in a scratch copy of `vendor/` with `src/agent` and `src/platform` of `symfony/ai` `main` e2b5e8476ead swapped in. At the head of the open symfony/ai#2617 (4584b4986628, `src/agent` and `src/platform` swapped in), which yields each round's result as a `ModelResult` update: `110 is identical to 230`. The tool round is counted, the cut round is not, because the cut round never reaches its `ModelResult`.
- **Earlier check:** 2026-11-12 against `symfony/ai` `main` e2b5e8476ead and the newest release v1.0.0-BETA1 (f620d0fc6790); duplicate search in `symfony/ai` issues and pull requests, open and closed, with the terms `Execution metadata token usage`, `IncompleteStreamException agent`, `token usage stream error`, `agent stream usage failed`. Found: #2617 (open pull request, each round's usage on the execution as it happens, for canceled and abandoned runs; not a run that fails), #2360 and #2363 (closed, stream listeners notified on a mid-stream error so a `StreamResult` keeps its usage; the platform side, done), #2898 (open issue, a mid-stream transport error re-sends the request; another bug), #2342 (open RFC, execution traces).
- **Pull requests and commits:** two open pull requests work on it, neither fixes this case (checked 2026-11-15 at `symfony/ai` `main` 1220483ebd24). #2617 (open, updated 2026-11-14, head 4584b4986628) and #2877 (open, `[Agent] Report the result of each model round as progress`, head dbca0c5b39e2: `Execution` sums each round's result as a progress update and `getMetadata()` returns that sum for a failed execution) both give `110 is identical to 230` with `src/agent` and `src/platform` of their heads swapped into a scratch copy of `vendor/`; `main` 1220483ebd24 gives `0 is identical to 230`. Searched with `is:pr Execution token usage` and `is:pr IncompleteStreamException agent`. Commits: `git log v1.0.0-BETA1..origin/main -- src/agent/src/Execution/` lists ca2c926d and da02d903 (#2860, cancellation reason) and c50e0c86 (a tool that returns nothing), none about the usage of a failed execution.
- **Form:** a comment on the open #2617, which already works on the bug and needs one more case; its text is `COMMENT.md`. Every `symfony/ai` pull request targets `main` (`CONTRIBUTING.md`).
- **Fix pull request:** `comment only`. The open #2617 already is the fix for the usage of an execution that does not complete; this report adds one more case (a stream that fails) that its `ModelResult` update misses, so the author can cover it in the same change instead of a second pull request competing with it.
- **Checked:** 2026-11-16 against `symfony/ai` `main` 999d5fbeea73 and the newest release v1.0.0-BETA1 (f620d0fc6790, no newer release); duplicate search in `symfony/ai` issues and pull requests, open and closed, with the terms `token usage execution`, `IncompleteStreamException`, `Execution metadata`, `ModelResult`, and `gh pr list --state all --search "ModelResult Execution"`. Found: #2617 (open, head 4584b4986628 unchanged, still misses a failed stream: `110 is identical to 230`), #2877 (open, head dbca0c5b39e2, same), #2360 (closed, platform side done), #2342 (open RFC); no commit on `main` since v1.0.0-BETA1 touches the usage of a failed execution.
- **Decision:** filed as a comment on symfony/ai#2617 (https://github.com/symfony/ai/pull/2617#issuecomment-6876304595, 2026-11-14, owner's account), with the text of `COMMENT.md`.
- **Status:** recheck #2617 for a reply; on 2026-11-16 it is still open at head 4584b4986628 and still misses a failed stream (`110 is identical to 230`). Checked 2026-11-22: #2617 is still open at head 4584b4986628 (label `Status: Needs Review`), with no reply to the comment.
