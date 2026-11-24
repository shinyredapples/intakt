<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The pull request of a branch as branch cleanup needs it: its state, when it was closed and its labels.
#[Exclude]
final readonly class BranchChange
{
    /**
     * @param list<string> $labels
     */
    public function __construct(
        public BranchChangeState $state,
        public ?DateTimeImmutable $closedAt,
        public array $labels,
    ) {
    }
}
