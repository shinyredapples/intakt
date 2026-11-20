<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\RecurringP1Found;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// A place recurs on each round that flags it on another head than the first round (by number) that flagged it; plain code decides it, because no reviewer's text may start an escalation. A place is decided only once no earlier round of the change waits for its judging, because that round could turn out to be the first; every settled round asks again for all decided recurrences not recorded yet, so one held back is recorded by whichever round settles last, judged or failed for good, in every judging order.
#[Exclude]
final readonly class RecurringP1
{
    /**
     * @param list<FlaggedRound>     $judged
     * @param list<int>              $waiting
     * @param list<RecurringP1Found> $recorded
     *
     * @return list<RecurringP1Found>
     */
    public function toRecord(array $judged, array $waiting, array $recorded): array
    {
        $known = [];

        foreach ($recorded as $found) {
            $known[$found->round][$found->key] = true;
        }

        return array_values(array_filter(
            $this->decided($judged, $waiting),
            static fn (RecurringP1Found $found): bool => !isset($known[$found->round][$found->key]),
        ));
    }

    /**
     * @param list<FlaggedRound> $judged
     * @param list<int>          $waiting
     *
     * @return list<RecurringP1Found>
     */
    private function decided(array $judged, array $waiting): array
    {
        $found = [];

        foreach ($this->firstToFlag($judged) as $key => $first) {
            if (!array_any($waiting, static fn (int $number): bool => $number < $first->number)) {
                $found = [...$found, ...$this->recurrencesOf($key, $first, $judged)];
            }
        }

        return $found;
    }

    /**
     * @param list<FlaggedRound> $judged
     *
     * @return list<RecurringP1Found>
     */
    private function recurrencesOf(string $key, FlaggedRound $first, array $judged): array
    {
        $found = [];

        foreach ($judged as $round) {
            if ($round->head !== $first->head && isset($round->places[$key])) {
                $found[] = new RecurringP1Found($round->id, $round->places[$key], $key);
            }
        }

        return $found;
    }

    /**
     * @param list<FlaggedRound> $judged
     *
     * @return array<string, FlaggedRound>
     */
    private function firstToFlag(array $judged): array
    {
        $first = [];

        foreach ($judged as $round) {
            foreach (array_keys($round->places) as $key) {
                if (!isset($first[$key]) || $round->number < $first[$key]->number) {
                    $first[$key] = $round;
                }
            }
        }

        return $first;
    }
}
