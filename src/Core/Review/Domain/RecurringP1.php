<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\RecurringP1Found;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// A place recurs on each round that flags it on another head than the first round (by number) that flagged it; plain code decides it, because no reviewer's text may start an escalation. A place is decided only once no earlier round of the change can still be judged, because that round could turn out to be the first, so a decided recurrence stays decided and a judging records exactly the ones it decides, in every judging order.
#[Exclude]
final readonly class RecurringP1
{
    /**
     * @param list<FlaggedRound> $others
     * @param list<int>          $pending
     *
     * @return list<RecurringP1Found>
     */
    public function foundWhenJudged(FlaggedRound $judged, array $others, array $pending = []): array
    {
        $before = $this->decided($others, [...$pending, $judged->number]);

        return array_values(array_filter(
            $this->decided([...$others, $judged], $pending),
            static fn (RecurringP1Found $found): bool => !array_any($before, static fn (RecurringP1Found $known): bool => $known->round === $found->round && $known->key === $found->key),
        ));
    }

    /**
     * @param list<FlaggedRound> $judged
     * @param list<int>          $pending
     *
     * @return list<RecurringP1Found>
     */
    private function decided(array $judged, array $pending): array
    {
        $found = [];

        foreach ($this->firstToFlag($judged) as $key => $first) {
            if (array_any($pending, static fn (int $number): bool => $number < $first->number)) {
                continue;
            }

            foreach ($judged as $round) {
                if ($round->head !== $first->head && isset($round->places[$key])) {
                    $found[] = new RecurringP1Found($round->id, $round->places[$key], $key);
                }
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
