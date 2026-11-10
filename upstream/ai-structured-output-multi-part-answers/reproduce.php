<?php

declare(strict_types=1);

use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Bridge\Anthropic\Factory;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Exception\ValidationException;
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

// Messages API answers as a model with tools and extended thinking sends them; only the HTTP layer is replaced.
function message(string $stopReason, array ...$content): MockResponse
{
    $body = ['id' => 'msg_01', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-4-5', 'content' => $content, 'stop_reason' => $stopReason, 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]];

    return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
}

function events(string $stopReason, array ...$content): MockResponse
{
    $events = [['type' => 'message_start', 'message' => ['id' => 'msg_01', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-4-5', 'content' => [], 'stop_reason' => null, 'usage' => ['input_tokens' => 10, 'output_tokens' => 1]]]];

    foreach ($content as $index => $block) {
        [$start, $delta] = match ($block['type']) {
            'thinking' => [['type' => 'thinking', 'thinking' => ''], [['type' => 'thinking_delta', 'thinking' => $block['thinking']], ['type' => 'signature_delta', 'signature' => $block['signature']]]],
            'tool_use' => [['type' => 'tool_use', 'id' => $block['id'], 'name' => $block['name'], 'input' => []], [['type' => 'input_json_delta', 'partial_json' => json_encode($block['input'], JSON_THROW_ON_ERROR)]]],
            default => [['type' => 'text', 'text' => ''], [['type' => 'text_delta', 'text' => $block['text']]]],
        };
        $events[] = ['type' => 'content_block_start', 'index' => $index, 'content_block' => $start];

        foreach ($delta as $change) {
            $events[] = ['type' => 'content_block_delta', 'index' => $index, 'delta' => $change];
        }

        $events[] = ['type' => 'content_block_stop', 'index' => $index];
    }

    $events[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => $stopReason], 'usage' => ['output_tokens' => 5]];
    $events[] = ['type' => 'message_stop'];
    $body = implode('', array_map(static fn (array $event): string => sprintf("event: %s\ndata: %s\n\n", $event['type'], json_encode($event, JSON_THROW_ON_ERROR)), $events));

    return new MockResponse($body, ['response_headers' => ['content-type' => 'text/event-stream']]);
}

/**
 * @param list<MockResponse> $responses
 * @param array<string, mixed> $options
 */
function decisionOf(array $responses, array $options): string
{
    $dispatcher = new EventDispatcher();
    $dispatcher->addSubscriber(new PlatformSubscriber());
    $dispatcher->addSubscriber(new ValidatorSubscriber());
    $platform = Factory::createPlatform('not-a-key', new MockHttpClient($responses), eventDispatcher: $dispatcher);
    $agent = new Agent($platform, 'claude-haiku-4-5', toolbox: new Toolbox([new ReadFile()]));

    try {
        $execution = $agent->call(new MessageBag(Message::ofUser('Review the change.')), ['response_format' => Answer::class, ...$options]);

        if (true === ($options['stream'] ?? false)) {
            foreach ($execution->getContent() as $ignored) {
            }
        }

        $answer = $execution->getResult()->getContent();

        return $answer instanceof Answer ? $answer->decision : 'a '.get_debug_type($answer).' instead of an Answer';
    } catch (ValidationException) {
        return 'refused by the Validator';
    } catch (Throwable $failure) {
        return $failure::class.': '.$failure->getMessage();
    }
}

$thought = ['type' => 'thinking', 'thinking' => 'The change looks fine.', 'signature' => 'signature-0'];
$narration = ['type' => 'text', 'text' => 'Let me read the file first.'];
$toolCall = ['type' => 'tool_use', 'id' => 'toolu_01', 'name' => 'read_file', 'input' => ['path' => 'src/Example.php']];
$verdict = ['type' => 'text', 'text' => '{"decision":"approve"}'];
$invalid = ['type' => 'text', 'text' => '{"decision":""}'];

// Each case holds what the structured output returns and what it should return.
$cases = [
    'control: the answer alone' => [decisionOf([message('end_turn', $verdict)], []), 'approve'],
    'control: the answer alone, streamed' => [decisionOf([events('end_turn', $verdict)], ['stream' => true]), 'approve'],
    'control: an invalid answer alone' => [decisionOf([message('end_turn', $invalid)], []), 'refused by the Validator'],
    'A: narration before a tool call' => [decisionOf([message('tool_use', $narration, $toolCall), message('end_turn', $verdict)], []), 'approve'],
    'B: the answer beside thinking' => [decisionOf([message('end_turn', $thought, $verdict)], []), 'approve'],
    'B: an invalid answer beside thinking' => [decisionOf([message('end_turn', $thought, $invalid)], []), 'refused by the Validator'],
    'C: streamed narration before a tool call' => [decisionOf([events('tool_use', $narration, $toolCall), events('end_turn', $verdict)], ['stream' => true]), 'approve'],
];

$failed = false;

foreach ($cases as $case => [$actual, $expected]) {
    echo $case, ': ', $actual, PHP_EOL;
    $failed = $failed || $expected !== $actual;
}

if ($failed) {
    echo 'Structured output does not return the typed answer of a model that thinks or calls tools', PHP_EOL;

    exit(1);
}

echo 'REPRODUCTION PASSES', PHP_EOL;
