<?php

declare(strict_types=1);

namespace Intakt\Core\Bench\Domain;

use Intakt\Core\Review\Contract\Finding;

final readonly class RunVerdict
{
    /**
     * @param list<string>                $caught      ids of the planted defects a finding matched
     * @param array<string, FailureCause> $missed      id of each defect no finding matched => its cause
     * @param list<Finding>               $falseAlarms unmatched findings on a defect-free change
     * @param list<Finding>               $unmatched   unmatched findings on a change with defects: reported, not counted
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
