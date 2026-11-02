<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Symfony\Component\HttpClient\Response\MockResponse;

// The event stream Anthropic sends for one answer, built from the content blocks of the unstreamed shape, so a test scripts a streamed round the way it scripts an unstreamed one.
final readonly class AnthropicStream
{
    /**
     * @param list<array<string, mixed>> $content
     * @param array<string, int>         $usage
     */
    public static function answering(array $content, array $usage, string $model = 'claude-sonnet-5-5'): MockResponse
    {
        // Split as Anthropic sends them, because the bridge reads the prompt and cache counts from message_start and the final output count from message_delta.
        $output = $usage['output_tokens'] ?? 0;
        unset($usage['output_tokens']);
        $asksForTools = array_any($content, static fn (array $block): bool => 'tool_use' === $block['type']);

        /** @var list<array{type: string, ...}> $events */
        $events = [['type' => 'message_start', 'message' => ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => $model, 'content' => [], 'stop_reason' => null, 'usage' => $usage + ['output_tokens' => 1]]]];
        foreach ($content as $index => $block) {
            array_push($events, ...self::blockEvents($index, $block));
        }
        $events[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => $asksForTools ? 'tool_use' : 'end_turn', 'stop_sequence' => null], 'usage' => ['output_tokens' => $output]];
        $events[] = ['type' => 'message_stop'];

        return new MockResponse(implode('', array_map(self::event(...), $events)), ['response_headers' => ['content-type' => 'text/event-stream']]);
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return list<array{type: string, ...}>
     */
    private static function blockEvents(int $index, array $block): array
    {
        $tool = 'tool_use' === $block['type'];
        $start = $tool ? ['type' => 'tool_use', 'id' => $block['id'], 'name' => $block['name'], 'input' => []] : ['type' => 'text', 'text' => ''];
        $delta = $tool ? ['type' => 'input_json_delta', 'partial_json' => json_encode($block['input'], JSON_THROW_ON_ERROR)] : ['type' => 'text_delta', 'text' => $block['text']];

        return [
            ['type' => 'content_block_start', 'index' => $index, 'content_block' => $start],
            ['type' => 'content_block_delta', 'index' => $index, 'delta' => $delta],
            ['type' => 'content_block_stop', 'index' => $index],
        ];
    }

    /**
     * @param array{type: string, ...} $event
     */
    private static function event(array $event): string
    {
        return sprintf("event: %s\ndata: %s\n\n", $event['type'], json_encode($event, JSON_THROW_ON_ERROR));
    }
}
