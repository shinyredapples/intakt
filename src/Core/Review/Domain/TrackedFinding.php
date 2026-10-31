<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\Finding;

final readonly class TrackedFinding
{
    /**
     * @param non-empty-list<int> $rounds
     */
    public function __construct(
        public string $id,
        public Finding $latest,
        public array $rounds,
        public FindingStatus $status,
    ) {
    }

    public function firstRound(): int
    {
        return array_first($this->rounds);
    }

    public function latestRound(): int
    {
        return array_last($this->rounds);
    }

    public function isOpen(): bool
    {
        return FindingStatus::Open === $this->status;
    }

    public function seenAgain(int $round, Finding $finding): self
    {
        return clone ($this, [
            'latest' => $finding,
            'rounds' => [...$this->rounds, $round],
        ]);
    }

    public function resolved(): self
    {
        return clone ($this, ['status' => FindingStatus::Resolved]);
    }
}
