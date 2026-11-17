Repository: symfony/ai. Posted as https://github.com/symfony/ai/issues/2945 on 2026-11-14; the text below is its body as posted.

Title: [Platform] Structured output fails when Anthropic narrates before a tool call, and skips validation beside thinking

### Description

An `Agent` with a toolbox calls an Anthropic model with `response_format` set to a class, and the structured-output `PlatformSubscriber` and `ValidatorSubscriber` are registered. Anthropic answers in several content blocks when the model thinks or writes text before a tool call, and the bridge returns them as a `MultiPartResult` (not streamed) or as one run of deltas (streamed). Three cases then go wrong:

- **A, text before a tool call (not streamed):** the round is `[TextResult "Let me read the file first.", ToolCallResult]`. `StructuredOutput\ResultConverter::convertMultiPart()` converts the first `TextResult`, here the narration, and throws `Cannot deserialize the content into the "Answer" class.`, so the agent never runs the tool.
- **B, the answer beside thinking (not streamed):** the final round is `[ThinkingResult, TextResult "{...}"]`. It becomes `[ThinkingResult, ObjectResult]` inside a `MultiPartResult`, and `ValidatorResultConverter` validates only a top-level `ObjectResult`. `Execution::asObject()` returns the object, but unvalidated: an answer that `#[NotBlank]` refuses when it comes alone passes when it comes beside thinking.
- **C, text before a tool call (streamed):** `PartialObjectStreamListener` buffers every `TextDelta` of the round, also the narration of a round that ends in a tool call, and `onComplete()` throws `Cannot json decode the streamed content.`.

The same setup returns and validates the object when the model sends only the answer.

### Versions

`symfony/ai-platform`, `symfony/ai-agent` and `symfony/ai-anthropic-platform` v1.0.0-BETA1, `symfony/serializer` and `symfony/http-client` v8.1.11, PHP 8.5.12. Still the same on `main` (c4cb5dad).

### How to reproduce

The script replays Messages API answers through `MockHttpClient` (no network, no key); it needs `symfony/ai-agent`, `symfony/ai-anthropic-platform`, `symfony/event-dispatcher`, `symfony/http-client` and `symfony/validator`:

```php
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Bridge\Anthropic\Factory;
use Symfony\AI\Platform\Exception\ValidationException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\AI\Platform\StructuredOutput\Validator\ValidatorSubscriber;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Validator\Constraints\NotBlank;

final class Answer
{
    #[NotBlank]
    public string $decision = '';
}

#[AsTool('read_file', 'Reads a file of the repository')]
final class ReadFile
{
    public function __invoke(string $path): string
    {
        return 'final class Example {}';
    }
}

// A Messages API answer, not streamed.
function message(string $stopReason, array ...$content): MockResponse
{
    $body = ['id' => 'msg_01', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-4-5', 'content' => $content, 'stop_reason' => $stopReason, 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]];

    return new MockResponse(json_encode($body), ['response_headers' => ['content-type' => 'application/json']]);
}

// The same answer as server-sent events (text and tool_use blocks only).
function events(string $stopReason, array ...$content): MockResponse
{
    $events = [['type' => 'message_start', 'message' => ['id' => 'msg_01', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-4-5', 'content' => [], 'stop_reason' => null, 'usage' => ['input_tokens' => 10, 'output_tokens' => 1]]]];
    foreach ($content as $index => $block) {
        [$start, $delta] = 'tool_use' === $block['type']
            ? [['type' => 'tool_use', 'id' => $block['id'], 'name' => $block['name'], 'input' => []], ['type' => 'input_json_delta', 'partial_json' => json_encode($block['input'])]]
            : [['type' => 'text', 'text' => ''], ['type' => 'text_delta', 'text' => $block['text']]];
        $events[] = ['type' => 'content_block_start', 'index' => $index, 'content_block' => $start];
        $events[] = ['type' => 'content_block_delta', 'index' => $index, 'delta' => $delta];
        $events[] = ['type' => 'content_block_stop', 'index' => $index];
    }
    $events[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => $stopReason], 'usage' => ['output_tokens' => 5]];
    $events[] = ['type' => 'message_stop'];
    $body = implode('', array_map(static fn (array $e): string => sprintf("event: %s\ndata: %s\n\n", $e['type'], json_encode($e)), $events));

    return new MockResponse($body, ['response_headers' => ['content-type' => 'text/event-stream']]);
}

function decisionOf(array $responses, array $options = []): string
{
    $dispatcher = new EventDispatcher();
    $dispatcher->addSubscriber(new PlatformSubscriber());
    $dispatcher->addSubscriber(new ValidatorSubscriber());
    $platform = Factory::createPlatform('not-a-key', new MockHttpClient($responses), eventDispatcher: $dispatcher);
    $agent = new Agent($platform, 'claude-haiku-4-5', toolbox: new Toolbox([new ReadFile()]));

    try {
        $execution = $agent->call(new MessageBag(Message::ofUser('Review the change.')), ['response_format' => Answer::class, ...$options]);
        if ($options['stream'] ?? false) {
            foreach ($execution->getContent() as $ignored) {
            }
        }
        $answer = $execution->asObject();

        return $answer instanceof Answer ? sprintf('decision "%s"', $answer->decision) : get_debug_type($answer).' instead of an Answer';
    } catch (ValidationException) {
        return 'refused by the Validator';
    } catch (Throwable $e) {
        return $e::class.': '.$e->getMessage();
    }
}

$thought = ['type' => 'thinking', 'thinking' => 'The change looks fine.', 'signature' => 'signature-0'];
$narration = ['type' => 'text', 'text' => 'Let me read the file first.'];
$toolCall = ['type' => 'tool_use', 'id' => 'toolu_01', 'name' => 'read_file', 'input' => ['path' => 'src/Example.php']];
$verdict = ['type' => 'text', 'text' => '{"decision":"approve"}'];
$invalid = ['type' => 'text', 'text' => '{"decision":""}'];

echo 'control, the answer alone: ', decisionOf([message('end_turn', $verdict)]), PHP_EOL;
echo 'control, an invalid answer alone: ', decisionOf([message('end_turn', $invalid)]), PHP_EOL;
echo 'A, narration before a tool call: ', decisionOf([message('tool_use', $narration, $toolCall), message('end_turn', $verdict)]), PHP_EOL;
echo 'B, the answer beside thinking: ', decisionOf([message('end_turn', $thought, $verdict)]), PHP_EOL;
echo 'B, an invalid answer beside thinking: ', decisionOf([message('end_turn', $thought, $invalid)]), PHP_EOL;
echo 'C, streamed narration before a tool call: ', decisionOf([events('tool_use', $narration, $toolCall), events('end_turn', $verdict)], ['stream' => true]), PHP_EOL;
```

### Actual behavior

```
control, the answer alone: decision "approve"
control, an invalid answer alone: refused by the Validator
A, narration before a tool call: Symfony\AI\Platform\Exception\RuntimeException: Cannot deserialize the content into the "Answer" class.
B, the answer beside thinking: decision "approve"
B, an invalid answer beside thinking: decision ""
C, streamed narration before a tool call: Symfony\AI\Platform\Exception\RuntimeException: Cannot json decode the streamed content.
```

### Expected behavior

```
A, narration before a tool call: decision "approve"
B, an invalid answer beside thinking: refused by the Validator
C, streamed narration before a tool call: decision "approve"
```

A round that asks for a tool is not the answer: its text is left alone, the agent runs the tool and converts the next round. The object beside thinking is validated like an object that comes alone.

### Possible solution

- In `StructuredOutput\ResultConverter::convertMultiPart()`, return a `MultiPartResult` that holds a `ToolCallResult` unconverted.
- Let `ValidatorResultConverter` validate the `ObjectResult` among the parts of a `MultiPartResult`.
- In `PartialObjectStreamListener`, skip the final decoding when the stream yielded a `ToolCallComplete`.

Which text is the answer when a final round holds two text blocks is a design question: the Anthropic bridge streams two text blocks as one run of `TextDelta`s, so a stream listener cannot tell them apart.

### Additional context

Related: #2771 had the same first-text pick for OpenAI commentary, solved on `main` by #2578 with a `CommentaryResult`. Anthropic has no such marker for text before a tool call. Open pull requests #2448 and #2527 edit the same files for other reasons.
