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
     * @return Generator<mixed>
     */
    #[Override]
    public function getDataStream(): Generator
    {
        foreach ($this->raw->getDataStream() as $key => $event) {
            if (self::opensATextBlock($event)) {
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

    private static function opensATextBlock(mixed $event): bool
    {
        return is_array($event)
            && 'content_block_start' === ($event['type'] ?? null)
            && is_array($event['content_block'] ?? null)
            && 'text' === ($event['content_block']['type'] ?? null);
    }
}
