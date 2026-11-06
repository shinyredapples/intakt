Repository: symfony/ai. Template: Bug Report (label `Bug`). Not posted: the owner approves this text first.

Title: [Platform][Codex] A turn without `turn.completed` is returned as a complete answer

### Description

`codex exec --json` ends a finished turn with a `turn.completed` event (it also carries the usage) and a failed one with `turn.failed`. The Codex bridge checks for neither: `RawProcessResult::getData()` returns the last `agent_message` whatever came after it, and `ResultConverter::convertStream()` yields its text and ends the same way. When the CLI exits with 0 before the turn ended, the caller gets a partial turn as a complete answer, with no usage and no error.

A real `codex exec` exits with 1 on failed and interrupted turns, and the bridge already throws there (`Codex CLI process failed`). The case left is an exit with 0 and no `turn.completed`: the CLI's in-process event channel closing before the turn ends, which `codex exec` treats as the end of the run. In `codex-rs/exec/src/lib.rs`, the event loop [breaks when `client.next_event()` returns `None`](https://github.com/openai/codex/blob/4688e49329637b8b50dd8450ef784020537e2215/codex-rs/exec/src/lib.rs#L1351-L1356), and `run_main()` then [exits with 1 only when `error_seen` is set](https://github.com/openai/codex/blob/4688e49329637b8b50dd8450ef784020537e2215/codex-rs/exec/src/lib.rs#L1426-L1430), which only an `error` notification or a failed or interrupted `turn/completed` sets; otherwise it returns `Ok(())`, exit code 0.

Codex was left out of #2169 on purpose. The exit code does cover failed and interrupted turns, but not this one: the exit code is 0, so only the missing `turn.completed` tells this turn from a finished one, the way `message_stop` does for the Claude Code bridge.

### Versions

`symfony/ai-codex-platform` v1.0.0-BETA1, `symfony/ai-platform` v1.0.0-BETA1, `symfony/process` v8.1.9, PHP 8.5.12. Still the same on `main` (6747a33).

### How to reproduce

`printf` stands in for the CLI and exits with 0, as `codex exec` does in the case above:

```php
use Symfony\AI\Platform\Bridge\Codex\RawProcessResult;
use Symfony\AI\Platform\Bridge\Codex\ResultConverter;
use Symfony\Component\Process\Process;

$process = new Process(['printf', '%s', '{"type":"turn.started"}'.PHP_EOL
    .'{"type":"item.completed","item":{"id":"item_0","type":"agent_message","text":"partial"}}'.PHP_EOL]);
$process->start();

$result = (new ResultConverter())->convert(new RawProcessResult($process));
echo $result::class, ': ', $result->getContent(), PHP_EOL;
```

With `['stream' => true]` and the same output, the stream yields `TextDelta("partial")` and ends without an error.

### Actual behavior

```
Symfony\AI\Platform\Result\TextResult: partial
```

### Expected behavior

`IncompleteStreamException` from `convert()`, and from the stream when it ends, when the output holds neither `turn.completed` nor `turn.failed`, so a caller can tell a cut-off turn from a finished one.

### Possible solution

`RawProcessResult::getData()` already reads every event; it can record whether `turn.completed` or `turn.failed` was seen, and the converter throws `IncompleteStreamException` when neither was. `convertStream()` does the same after its loop. The converter tests that feed a bare `agent_message` without `turn.completed` and expect a `TextResult` would then need the event added.

### Additional context

Related, other bugs in the same files: #2712 (`RawProcessResult` parses nothing on Windows, because the JSON Lines are split on `PHP_EOL`) and its fix #2713 (open). Neither changes how a missing `turn.completed` is handled.

Also related, not filed on its own: `getData()` attaches the usage of `turn.completed` only to an agent message, so a completed turn without one loses its usage. Through a `Platform` this cannot be reached, because `DeferredResult` runs the usage extractor only after `convert()` succeeds, and `convert()` throws when there is no answer.
