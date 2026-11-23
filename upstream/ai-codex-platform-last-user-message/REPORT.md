# [Codex] Every user message but the last is dropped from the prompt

Filed as pull request symfony/ai#2891 (merged 2026-11-06, not released yet). Versions: `symfony/ai-codex-platform` v1.0.0-BETA1, `symfony/ai-platform` v1.0.0-BETA1, PHP 8.5.12.

## What happens

`Contract\MessageBagNormalizer` builds the CLI's prompt from the last user message only, and from that message only its first text block (`break 2`). A caller that sends its context as several user messages (a task, then the material to judge) silently loses all of them but the last, and a caller that sends one message with several text blocks loses all blocks but the first; the model answers without them. `reproduce.php` normalizes a bag with two user messages and one with a single user message of two text blocks, and prints:

```
two user messages, prompt: "The diff: SECOND"
one user message with two text blocks, prompt: "The task: FIRST"
two user messages: part of the user text is dropped from the prompt
two text blocks: part of the user text is dropped from the prompt
```

## Expected

Every user message in the prompt, in order (for example separated by a blank line), or an exception when the bag holds more than the CLI's one prompt can carry.

## Effect in Intakt

`src/Core/Ai/Infrastructure/CodexMessageBagNormalizer.php` replaces the bridge's normalizer and joins the text of every user message, separated by a blank line.

## Upstream status

- **Checked:** 2026-11-14. Ran against the default branch `main` of `symfony/ai` (c4cb5da): the reproduction passes. The newest release, v1.0.0-BETA1, still fails: `php bin/console intakt:upstream:run` prints `still fails  ai-codex-platform-last-user-message`.
- **Existing issue or pull request:** symfony/ai#2544 (open, "[Platform] ClaudeCode and Codex bridges drop conversation history; support multi-turn via CLI session resume") stays open as the session-resume feature; it is not this bug.
- **Pull requests and commits:** filed as pull request symfony/ai#2891 by Intakt (merged 2026-11-06), which keeps every user message and every text block in the Codex prompt.
- **Status:** `filed as pull request` https://github.com/symfony/ai/pull/2891, merged 2026-11-06 on the development branch (recorded 2026-11-20); the first release after v1.0.0-BETA1 will carry it, and none is out yet. Once it is: record that release here, and revisit `src/Core/Ai/Infrastructure/CodexMessageBagNormalizer.php`. Checked 2026-11-22: no `symfony/ai` release after v1.0.0-BETA1 yet (the newest tag is still v1.0.0-BETA1).
