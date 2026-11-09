<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

final readonly class StartCheckReport
{
    /**
     * @param list<StartCheckItem> $items
     */
    public function __construct(
        public array $items,
    ) {
    }

    // A job with a failed item ran with less than it was told it can do.
    public function reduced(): bool
    {
        return [] !== $this->failed();
    }

    /**
     * @return list<StartCheckItem>
     */
    public function failed(): array
    {
        return array_values(array_filter($this->items, static fn (StartCheckItem $item): bool => !$item->passed));
    }
}
