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

## Upstream status

Checked on 2026-11-02.

- **Pinned versions:** still fails. `php bin/console intakt:upstream:run` at `symfony/ai-*` v1.0.0-BETA1: `still fails  ai-codex-platform-last-user-message`.
- **Development branch:** still fails. The same run in a scratch copy with every `symfony/ai-*` package at `main` (`symfony/ai-codex-platform` 937734bb71b9, `symfony/ai-platform` 17c452fa350f): `still fails  ai-codex-platform-last-user-message`.
- **Existing issue or pull request:** symfony/ai#2544 (open, "[Platform] ClaudeCode and Codex bridges drop conversation history; support multi-turn via CLI session resume"), the same bug: it names the `MessageBagNormalizer` of both CLI bridges keeping only the system prompt and the last user message, and asks for session resume as the fix. Related, not the same: #2545 (open RFC, persistent-process clients for coding CLIs).
- **Searches** (`symfony/ai` issues and pull requests, open and closed): `Codex MessageBagNormalizer`, `Codex "user message"`, `multi-turn`, `label:Platform Codex`.
- **Decision:** `already reported` (https://github.com/symfony/ai/issues/2544). This reproduction adds a case the issue does not show: several user messages in one turn, with no assistant message between them.
