# [Codex] Every user message but the last is dropped from the prompt

Draft, not posted. Versions: `symfony/ai-codex-platform` v1.0.0-BETA1, `symfony/ai-platform` v1.0.0-BETA1, PHP 8.5.12.

## What happens

`Contract\MessageBagNormalizer` builds the CLI's prompt from the last user message only. A caller that sends its context as several user messages (a task, then the material to judge) silently loses all of them but the last, and the model answers without them. `reproduce.php` normalizes a bag with two user messages and prints:

```
prompt: "The diff: SECOND"
Every user message but the last is dropped from the prompt
```

## Expected

Every user message in the prompt, in order (for example separated by a blank line), or an exception when the bag holds more than the CLI's one prompt can carry.

## Effect in Intakt

`src/Core/Ai/Infrastructure/CodexMessageBagNormalizer.php` replaces the bridge's normalizer and joins the text of every user message, separated by a blank line.
