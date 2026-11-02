# [Codex] The usage of a turn without an agent message is lost

Draft, not posted. Versions: `symfony/ai-codex-platform` v1.0.0-BETA1, `symfony/ai-platform` v1.0.0-BETA1, `symfony/process` v8.1.9, PHP 8.5.12.

## What happens

`RawProcessResult::getData()` attaches the usage of `turn.completed` only to an agent message. When a completed turn has none (only reasoning, for example), it returns an empty array, so `TokenUsageExtractor` finds no usage and the tokens the account spent are not reported. `reproduce.php` extracts the usage of such a turn and prints:

```
usage: none
The usage of a turn without an agent message is lost
```

## Expected

The usage of `turn.completed` whatever the turn produced, while the converter still fails on the missing answer.

## Effect in Intakt

`src/Core/Ai/Infrastructure/CompletedCodexTurn.php` reads the usage of `turn.completed` from the process output when the bridge returned no agent message.
