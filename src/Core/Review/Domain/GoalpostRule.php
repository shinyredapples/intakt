<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;

// Plain code decides this, because a prompt alone lets a reviewer keep moving the goalposts between rounds.
final readonly class GoalpostRule
{
    private const int FIRST_ROUND_UNDER_THE_RULE = 2;

    // Applied before the verdict is stored, so the panel counts the same severities the ledger records.
    /**
     * @param list<Finding> $open
     */
    public function applyToVerdict(int $round, Verdict $verdict, array $open): Verdict
    {
        $severities = array_column(array_map(static fn (Finding $finding): array => [$finding->id, $finding->severity], $open), 1, 0);

        return $verdict->withFindings(array_map(
            fn (Finding $finding): Finding => $finding->repeat && isset($severities[$finding->id])
                ? $finding->withSeverity($severities[$finding->id])
                : $this->applyTo($round, $finding),
            $verdict->findings,
        ));
    }

    // Only a new finding is passed in: a repeat keeps the severity it was first stored with, and the rule never raises one.
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
