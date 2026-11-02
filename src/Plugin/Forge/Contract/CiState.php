<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

// The workflow runs and check suites of exactly one head; folding them into one outcome is the caller's rule, not each forge's.
final readonly class CiState
{
    /**
     * @param list<CiRun> $runs
     */
    public function __construct(
        public CommitId $head,
        public array $runs,
    ) {
    }

    public function has(CiRunState $state): bool
    {
        return array_any($this->runs, static fn (CiRun $run): bool => $run->state === $state);
    }

    public function isEmpty(): bool
    {
        return [] === $this->runs;
    }
}
