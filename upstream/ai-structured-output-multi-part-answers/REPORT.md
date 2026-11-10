# [Platform] Structured output misses the typed answer of a model that thinks or calls tools

Draft, not filed. Versions: `symfony/ai-platform`, `symfony/ai-agent` and `symfony/ai-anthropic-platform` v1.0.0-BETA1, `symfony/serializer` v8.1.11, `symfony/http-client` v8.1.11, `symfony/event-dispatcher` v8.1.5, PHP 8.5.12.

## What happens

An `Agent` with a toolbox calls an Anthropic model with `response_format` set to a class; the structured-output `PlatformSubscriber` converts every round of the agent's loop. Anthropic answers in several content blocks when the model thinks or says something before a tool call, and the bridge's `ResultConverter` returns them as a `MultiPartResult` (non-streamed) or one run of deltas (streamed). Three shapes of a real conversation then fail:

- **A, narration before a tool call (non-streamed):** the round is `[TextResult "Let me read the file first.", ToolCallResult]`. `StructuredOutput\ResultConverter::convertMultiPart()` converts the first `TextResult` it finds, here the narration, and throws, so the agent never runs the tool.
- **B, the answer beside thinking (non-streamed):** the final round is `[ThinkingResult, TextResult "{...}"]`. The conversion makes it `[ThinkingResult, ObjectResult]`, but the result stays a `MultiPartResult`, whose content is the list of parts: the agent's result content is an `array`, not the object. `ValidatorResultConverter` checks only an `ObjectResult`, so the object among the parts is never validated: an answer with an empty `decision` that the `#[NotBlank]` constraint refuses when it comes alone passes when it comes beside thinking. (A plain platform call returns the same `MultiPartResult`, and `DeferredResult::asObject()` finds the object among its parts, but without validation.)
- **C, streamed narration before a tool call:** `PartialObjectStreamListener` buffers every `TextDelta` of the round, also the narration of a round that ends in a tool call, and its `onComplete()` throws while decoding the narration.

`reproduce.php` replays recorded Messages API answers through `MockHttpClient` (no network, no key) and prints:

```
control: the answer alone: approve
control: the answer alone, streamed: approve
control: an invalid answer alone: refused by the Validator
A: narration before a tool call: Symfony\AI\Platform\Exception\RuntimeException: Cannot deserialize the content into the "Answer" class.
B: the answer beside thinking: a array instead of an Answer
B: an invalid answer beside thinking: a array instead of an Answer
C: streamed narration before a tool call: Symfony\AI\Platform\Exception\RuntimeException: Cannot json decode the streamed content.
Structured output does not return the typed answer of a model that thinks or calls tools
```

## Expected

- A: a round that asks for tools is not the answer: the structured output leaves its text alone, and the agent runs the tool and converts the next round.
- B: the typed object is reachable as the answer (the agent's result content returns it, with the thinking still kept for the replay), and the Validator subscriber checks it, so the invalid answer is refused as it is when alone.
- C: the narration of a streamed round that ends in a tool call is not decoded as the answer.

The controls show the same setup returns the typed object when the model sends nothing but the answer.

## Possible fix

In `StructuredOutput\ResultConverter`, return a `MultiPartResult` that holds a `ToolCallResult` unconverted, and let `ValidatorResultConverter` validate the one `ObjectResult` among the parts (and the agent's result content return it). In `PartialObjectStreamListener`, skip the final decoding when the stream yielded a `ToolCallComplete`. Which text is the answer when a final round holds two text blocks is a design question for upstream; the Anthropic bridge streams two text blocks as one run of `TextDelta`s, so a streamed listener cannot tell them apart.

## Effect in Intakt

`src/Core/Review/Infrastructure/StrictVerdictConverter.php` drops the narration of an unstreamed round that asks for tools, converts the one text part beside the thinking and validates it; `StreamedAnswer` and `AnthropicTextBlocks` keep a streamed round's narration from the structured output; `ReviewerPrompt::verdictIn()` reads the verdict from its part. They stay until a release fixes this.

## Upstream status

- **Pinned versions:** still fails. `php bin/console intakt:upstream:run` at `symfony/ai-*` v1.0.0-BETA1: `still fails  ai-structured-output-multi-part-answers`.
- **Development branch:** not checked. This build made no network call (maintainer decision of 2026-11-09), so the run against `main` in a scratch copy is left to the owner.
- **Existing issue or pull request:** not searched, for the same reason. Searches to make (`symfony/ai` issues and pull requests, open and closed): `structured output MultiPartResult`, `structured output tool call`, `PartialObjectStreamListener tool`, `response_format thinking`.
- **Decision:** none yet: the form (a fix pull request against `main` for `ResultConverter` and `PartialObjectStreamListener`, or an issue for case B) waits for the check above.
- **Status:** `recheck needed before filing`: the owner runs the reproduction against `main`, makes the duplicate search and decides the form.
