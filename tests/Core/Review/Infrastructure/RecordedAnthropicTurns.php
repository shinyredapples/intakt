<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Symfony\Component\HttpClient\Response\MockResponse;

// Shaped like the Messages API answers of a real model, so only the HTTP layer is replaced.
final readonly class RecordedAnthropicTurns
{
    /**
     * @param list<array<string, mixed>> $content
     */
    private function __construct(private string $stopReason, private array $content)
    {
    }

    public static function askingForATool(): self
    {
        return new self('tool_use', []);
    }

    public static function answering(): self
    {
        return new self('end_turn', []);
    }

    public function thinking(string $thought): self
    {
        return $this->with(['type' => 'thinking', 'thinking' => $thought, 'signature' => 'signature-'.count($this->content)]);
    }

    public function saying(string $text): self
    {
        return $this->with(['type' => 'text', 'text' => $text]);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function callingATool(string $id, string $tool, array $input): self
    {
        return $this->with(['type' => 'tool_use', 'id' => $id, 'name' => $tool, 'input' => $input]);
    }

    public function asOneMessage(): MockResponse
    {
        return new MockResponse(json_encode($this->message($this->content, $this->stopReason), JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
    }

    public function asServerSentEvents(): MockResponse
    {
        $events = [['type' => 'message_start', 'message' => $this->message([], null)]];

        foreach ($this->content as $index => $block) {
            $events = [...$events, ...$this->eventsOf($index, $block)];
        }

        $events[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => $this->stopReason], 'usage' => ['output_tokens' => 5]];
        $events[] = ['type' => 'message_stop'];

        $body = '';

        foreach ($events as $event) {
            $body .= sprintf("event: %s\ndata: %s\n\n", $event['type'], json_encode($event, JSON_THROW_ON_ERROR));
        }

        return new MockResponse($body, ['response_headers' => ['content-type' => 'text/event-stream']]);
    }

    /**
     * @param array<string, mixed> $block
     */
    private function with(array $block): self
    {
        return new self($this->stopReason, [...$this->content, $block]);
    }

    /**
     * @param list<array<string, mixed>> $content
     *
     * @return array<string, mixed>
     */
    private function message(array $content, ?string $stopReason): array
    {
        return ['id' => 'msg_01', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'content' => $content, 'stop_reason' => $stopReason, 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]];
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return list<array<string, mixed>>
     */
    private function eventsOf(int $index, array $block): array
    {
        $delta = match ($block['type']) {
            'thinking' => [['type' => 'thinking_delta', 'thinking' => $block['thinking']], ['type' => 'signature_delta', 'signature' => $block['signature']]],
            'tool_use' => [['type' => 'input_json_delta', 'partial_json' => json_encode($block['input'], JSON_THROW_ON_ERROR)]],
            default => [['type' => 'text_delta', 'text' => $block['text']]],
        };
        $start = ['type' => $block['type']] + array_diff_key($block, ['input' => 1, 'text' => 1, 'thinking' => 1, 'signature' => 1]) + match ($block['type']) {
            'tool_use' => ['input' => []],
            'thinking' => ['thinking' => ''],
            default => ['text' => ''],
        };

        return [
            ['type' => 'content_block_start', 'index' => $index, 'content_block' => $start],
            ...array_map(static fn (array $change): array => ['type' => 'content_block_delta', 'index' => $index, 'delta' => $change], $delta),
            ['type' => 'content_block_stop', 'index' => $index],
        ];
    }
}
