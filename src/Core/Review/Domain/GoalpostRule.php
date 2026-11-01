<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\Severity;

// Plain code decides this, because a prompt alone lets a reviewer keep moving the goalposts between rounds.
final readonly class GoalpostRule
{
    private const int FIRST_ROUND_UNDER_THE_RULE = 2;

    // Only a new finding is passed in: a repeat keeps the severity it was first reported with, and the rule never raises one.
    public function applyTo(int $round, Finding $finding): Finding
    {
        if ($this->mayBlock($round, $finding)) {
            return $finding;
        }

        return $finding->withSeverity(Severity::P3);
    }

    private function mayBlock(int $round, Finding $finding): bool
    {
        return $round < self::FIRST_ROUND_UNDER_THE_RULE
            || Severity::P3 === $finding->severity
            || $finding->regression
            || '' !== trim($finding->requirement ?? '');
    }
}
