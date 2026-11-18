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
    // The act findings of both rounds one by one, never merged entries, because a merged entry keeps the text and symbol of whichever finding came first at its line. Each pair is this round's first P1 at a place and the earlier one there.
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
        $paired = [];

        foreach ($this->p1sOf($current) as $finding) {
            $place = $this->placeOf($finding);

            if (null === $place || isset($paired[$place])) {
                continue;
            }

            $before = array_find($earlierP1s, fn (Finding $one): bool => $this->placeOf($one) === $place);

            if ($before instanceof Finding) {
                $paired[$place] = true;
                $recurring[] = [$finding, $before];
            }
        }

        return $recurring;
    }

    // The file and the symbol, a missing symbol being a place of its own (the file as a whole), never the line, because the fix of a finding moves the lines around it on the next head. JSON encoded, so no file and symbol can shift into another pair; none without a file.
    public function placeOf(Finding $finding): ?string
    {
        return null === $finding->file ? null : json_encode([$finding->file, $finding->symbol], JSON_THROW_ON_ERROR);
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
}
