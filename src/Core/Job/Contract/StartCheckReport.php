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

    // A failed item means the job ran with less than it was told it can do, so the caller must treat the job's result with that in mind.
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
