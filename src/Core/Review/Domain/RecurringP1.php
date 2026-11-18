<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\Severity;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// A P1 recurs when the change's round on the earlier head flagged a P1 in the same place; a rule, not a service.
#[Exclude]
final readonly class RecurringP1
{
    /**
     * @param list<Finding> $earlier the act entries of the change's judged round on the earlier head
     * @param list<Finding> $current the act entries of this round
     *
     * @return list<array{Finding, Finding}> each recurring P1 of this round with the earlier P1 in its place
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
