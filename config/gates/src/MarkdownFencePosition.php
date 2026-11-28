<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class MarkdownFencePosition
{
    /**
     * @param list<int> $lists
     */
    public function __construct(
        public array $lists,
        public ?string $fence,
        public MarkdownLineKind $kind,
    ) {
    }
}
