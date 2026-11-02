# [Codex] A turn that never completed is returned as a complete answer when not streaming

Draft, not posted. Versions: `symfony/ai-codex-platform` v1.0.0-BETA1, `symfony/ai-platform` v1.0.0-BETA1, `symfony/process` v8.1.9, PHP 8.5.12.

## What happens

`codex exec --json` ends a finished turn with a `turn.completed` event, which also carries the usage. `RawProcessResult::getData()` returns the last `agent_message` whether or not that event arrived, so a stream that was cut off after a message reads as a complete answer, without usage. `reproduce.php` converts a stream that stops after the agent message and prints:

```
Symfony\AI\Platform\Result\TextResult: "{"verdict":"approve"}"
A turn that never completed passes for a complete answer
```

## Expected

`IncompleteStreamException` when the output holds no `turn.completed` (or `turn.failed`) event, so a caller can tell a cut-off turn from a finished one.

## Effect in Intakt

`src/Core/Ai/Infrastructure/CompletedCodexTurn.php` wraps the bridge's raw result and raises `IncompleteStreamException` when an agent message comes without the usage of `turn.completed`.
