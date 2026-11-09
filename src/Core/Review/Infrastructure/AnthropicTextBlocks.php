<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Generator;
use Override;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Counted from the raw events, because the Anthropic bridge streams two text blocks as one run of text deltas; excluded from the container because one is built per answer.
#[Exclude]
final class AnthropicTextBlocks implements RawResultInterface
{
    private int $opened = 0;

    public function __construct(
        private readonly RawResultInterface $raw,
    ) {
    }

    #[Override]
    public function getData(): array
    {
        return $this->raw->getData();
    }

    /**
     * @return Generator<array<string, mixed>>
     */
    #[Override]
    public function getDataStream(): Generator
    {
        foreach ($this->raw->getDataStream() as $key => $event) {
            if ($this->opensATextBlock($event)) {
                ++$this->opened;
            }

            yield $key => $event;
        }
    }

    #[Override]
    public function getObject(): object
    {
        return $this->raw->getObject();
    }

    public function opened(): int
    {
        return $this->opened;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function opensATextBlock(array $event): bool
    {
        return 'content_block_start' === ($event['type'] ?? null)
            && is_array($event['content_block'] ?? null)
            && 'text' === ($event['content_block']['type'] ?? null);
    }
}
