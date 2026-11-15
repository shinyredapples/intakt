<?php

declare(strict_types=1);

namespace Intakt\Core\Bench\Contract;

use Intakt\Core\Review\Contract\Finding;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Excluded because the bench builds it, not the container.
#[Exclude]
final readonly class RunVerdict
{
    /**
     * @param list<string>                $caught
     * @param array<string, FailureCause> $missed
     * @param list<Finding>               $falseAlarms
     * @param list<Finding>               $unmatched
     */
    public function __construct(
        public bool $defectFree,
        public array $caught,
        public array $missed,
        public array $falseAlarms,
        public array $unmatched,
        public ?string $harnessFailure,
    ) {
    }

    public function isHarnessFailure(): bool
    {
        return null !== $this->harnessFailure;
    }
}
