# [Platform] Structured output misses the typed answer of a model that thinks or calls tools

Filed as symfony/ai#2945 on 2026-11-14 (`ISSUE.md` is the posted text). Versions: `symfony/ai-platform`, `symfony/ai-agent` and `symfony/ai-anthropic-platform` v1.0.0-BETA1, `symfony/serializer` v8.1.11, `symfony/http-client` v8.1.11, `symfony/event-dispatcher` v8.1.5, PHP 8.5.12.

## What happens

An `Agent` with a toolbox calls an Anthropic model with `response_format` set to a class; the structured-output `PlatformSubscriber` converts every round of the agent's loop. Anthropic answers in several content blocks when the model thinks or says something before a tool call, and the bridge's `ResultConverter` returns them as a `MultiPartResult` (non-streamed) or one run of deltas (streamed). Three shapes of a real conversation then fail:

- **A, narration before a tool call (non-streamed):** the round is `[TextResult "Let me read the file first.", ToolCallResult]`. `StructuredOutput\ResultConverter::convertMultiPart()` converts the first `TextResult` it finds, here the narration, and throws, so the agent never runs the tool.
- **B, the answer beside thinking (non-streamed):** the final round is `[ThinkingResult, TextResult "{...}"]`. The conversion makes it `[ThinkingResult, ObjectResult]` inside a `MultiPartResult`. `Execution::asObject()` finds the object among the parts and returns it, but `ValidatorResultConverter` checks only a top-level `ObjectResult`, so that object is never validated: an answer with an empty `decision` that the `#[NotBlank]` constraint refuses when it comes alone passes when it comes beside thinking.
- **C, streamed narration before a tool call:** `PartialObjectStreamListener` buffers every `TextDelta` of the round, also the narration of a round that ends in a tool call, and its `onComplete()` throws while decoding the narration.

`reproduce.php` replays recorded Messages API answers through `MockHttpClient` (no network, no key), reads every answer through `Execution::asObject()` and prints:

```
control: the answer alone: decision "approve"
control: the answer alone, streamed: decision "approve"
control: an invalid answer alone: refused by the Validator
A: narration before a tool call: Symfony\AI\Platform\Exception\RuntimeException: Cannot deserialize the content into the "Answer" class.
B: the answer beside thinking: decision "approve"
B: an invalid answer beside thinking: decision ""
C: streamed narration before a tool call: Symfony\AI\Platform\Exception\RuntimeException: Cannot json decode the streamed content.
Structured output fails or skips validation for a model that thinks or calls tools
```

## Expected

- A: a round that asks for tools is not the answer: the structured output leaves its text alone, and the agent runs the tool and converts the next round.
- B: the Validator subscriber checks the object beside the thinking as it checks an object that comes alone, so the invalid answer is refused.
- C: the narration of a streamed round that ends in a tool call is not decoded as the answer.

The controls show the same setup returns the typed object when the model sends nothing but the answer.

## Possible fix

In `StructuredOutput\ResultConverter`, return a `MultiPartResult` that holds a `ToolCallResult` unconverted, and let `ValidatorResultConverter` validate the `ObjectResult` among the parts of a `MultiPartResult`. In `PartialObjectStreamListener`, skip the final decoding when the stream yielded a `ToolCallComplete`. Which text is the answer when a final round holds two text blocks is a design question for upstream; the Anthropic bridge streams two text blocks as one run of `TextDelta`s, so a streamed listener cannot tell them apart.

## Effect in Intakt

`src/Core/Review/Infrastructure/StrictVerdictConverter.php` drops the narration of an unstreamed round that asks for tools, converts the one text part beside the thinking and validates it; `StreamedAnswer` and `AnthropicTextBlocks` keep a streamed round's narration from the structured output; `ReviewerPrompt::verdictIn()` reads the verdict from its part. They stay until a release fixes this.

## Upstream status

- **Checked:** 2026-11-14. Ran against the default branch `main` of `symfony/ai` (c4cb5da): still fails, cases A, B and C as above. The newest release, v1.0.0-BETA1, fails the same way: `php bin/console intakt:upstream:run` prints `still fails  ai-structured-output-multi-part-answers`.
- **Correction to case B:** the first draft read the answer through the agent's result content, which is the list of parts, and reported that the object is not reachable. `Execution::asObject()` finds the object among the parts and returns it, so case B is only that this object is never validated. `reproduce.php` now reads every answer through `Execution::asObject()`, and case B fails on the invalid answer beside thinking (`decision ""` instead of `refused by the Validator`).
- **Related upstream:** symfony/ai#2771 had the same first-text pick for OpenAI commentary, solved on `main` by #2578 with a `CommentaryResult`; Anthropic has no such marker for text before a tool call. The open pull requests #2448 and #2527 edit the same files for other reasons. None of them fixes these cases.
- **Decision:** `filed as` https://github.com/symfony/ai/issues/2945 (2026-11-14), an issue with the text of `ISSUE.md` as posted, approved by the owner.
- **Status:** filed as issue [symfony/ai#2945](https://github.com/symfony/ai/issues/2945): watch it for a maintainer reply. Once a release fixes it: record that release here, and revisit the workarounds named in "Effect in Intakt". Checked 2026-11-22: #2945 is open, label `Platform`, no replies.
