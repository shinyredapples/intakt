<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Symfony\AI\Platform\Bridge\ClaudeCode\TokenUsageExtractor;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

// The stream-json events of the job's Claude Code, because only its own events, never the model's text, show what it read and spent.
final readonly class ClaudeCodeJobEvents
{
    private const string TYPE = 'type';

    // The only tool of the job's Claude Code that reads one file, because Grep and Glob can answer without opening a file of the checkout.
    private const string READ_TOOL = 'Read';

    /**
     * @param list<array<mixed>> $events
     */
    public function __construct(
        private array $events,
    ) {
    }

    // The last result event, because the stream ends on it; refused when marked as an error, because such a result (a rate limit, a refused login) is the CLI's own text, never the reviewer's answer.
    public function answer(): ?string
    {
        $result = $this->lastResult();

        if (null === $result || false !== ($result['is_error'] ?? null)) {
            return null;
        }

        return is_string($result['result'] ?? null) ? $result['result'] : null;
    }

    // A Read call of a file under the checkout that the CLI answered without a tool error, because a call that failed or read elsewhere showed the reviewer nothing of the change.
    public function readACheckoutFile(): bool
    {
        $reads = array_map(static fn (array $block): mixed => $block['id'] ?? null, array_filter($this->blocksOf('assistant'), $this->readsInTheCheckout(...)));
        $answered = array_map(static fn (array $block): mixed => $block['tool_use_id'] ?? null, array_filter($this->blocksOf('user'), $this->succeeded(...)));

        return [] !== array_intersect(array_filter($reads, is_string(...)), array_filter($answered, is_string(...)));
    }

    // The usage of the final result, read by the same extractor as for the CLI in the app's own process, because that result covers every turn of the run.
    /**
     * @return list<TokenUsageInterface>
     */
    public function usage(): array
    {
        $result = $this->lastResult();

        return ReportedUsage::in(new TokenUsageExtractor(), null === $result ? [] : [$result]);
    }

    /**
     * @return array<mixed>|null
     */
    private function lastResult(): ?array
    {
        return array_last(array_filter($this->events, static fn (array $event): bool => 'result' === ($event[self::TYPE] ?? null)));
    }

    /**
     * @return list<array<mixed>>
     */
    private function blocksOf(string $type): array
    {
        $blocks = [];
        foreach ($this->events as $event) {
            $blocks = [...$blocks, ...$this->contentOf($type === ($event[self::TYPE] ?? null) ? $event['message'] ?? null : null)];
        }

        return $blocks;
    }

    /**
     * @param array<mixed> $block
     */
    private function readsInTheCheckout(array $block): bool
    {
        $input = 'tool_use' === ($block[self::TYPE] ?? null) && self::READ_TOOL === ($block['name'] ?? null) ? $block['input'] ?? null : null;
        $path = is_array($input) ? $input['file_path'] ?? null : null;

        return is_string($path) && $this->inTheCheckout($path);
    }

    /**
     * @param array<mixed> $block
     */
    private function succeeded(array $block): bool
    {
        return 'tool_result' === ($block[self::TYPE] ?? null) && true !== ($block['is_error'] ?? false);
    }

    /**
     * @return list<array<mixed>>
     */
    private function contentOf(mixed $message): array
    {
        $content = is_array($message) ? $message['content'] ?? null : null;

        return is_array($content) ? array_values(array_filter($content, is_array(...))) : [];
    }

    // Under the mount point and without a step up, because the CLI resolves a dotted path itself and could leave the checkout.
    private function inTheCheckout(string $path): bool
    {
        return str_starts_with($path, ReviewJob::CHECKOUT.'/') && !in_array('..', explode('/', $path), true);
    }
}
