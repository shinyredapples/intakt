<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\Severity;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Plain code decides that a P1 recurs, because no reviewer's text may start an escalation; a rule, not a service.
#[Exclude]
final readonly class RecurringP1
{
    // Act entries of both rounds, because only what the builder was told to fix can come back; each pair is this round's P1 and the earlier one in its place.
    /**
     * @param list<Finding> $earlier
     * @param list<Finding> $current
     *
     * @return list<array{Finding, Finding}>
     */
    public function in(array $earlier, array $current): array
    {
        $earlierP1s = $this->p1sOf($earlier);
        $recurring = [];

        foreach ($this->p1sOf($current) as $finding) {
            $before = array_find($earlierP1s, fn (Finding $one): bool => $this->inSamePlace($one, $finding));

            if ($before instanceof Finding) {
                $recurring[] = [$finding, $before];
            }
        }

        return $recurring;
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    private function p1sOf(array $findings): array
    {
        return array_values(array_filter($findings, static fn (Finding $finding): bool => Severity::P1 === $finding->severity));
    }

    // The place is the file and, when both name one, the symbol, never the line, because the fix of a finding moves the lines around it on the next head.
    private function inSamePlace(Finding $one, Finding $other): bool
    {
        if (null === $one->file || $one->file !== $other->file) {
            return false;
        }

        return null === $one->symbol || null === $other->symbol || $one->symbol === $other->symbol;
    }
}
