<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\RecurringP1Found;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// A round's P1 recurs when an earlier round (by number) of another head flagged its place; plain code decides it, because no reviewer's text may start an escalation. The rule only grows as rounds are judged, so every judging order ends with the same recurrences.
#[Exclude]
final readonly class RecurringP1
{
    /**
     * @param list<FlaggedRound> $others the change's other judged rounds
     *
     * @return list<RecurringP1Found>
     */
    public function foundWhenJudged(FlaggedRound $judged, array $others): array
    {
        return [...$this->recurrencesOf($judged, $others), ...$this->laterRecurrencesAgainst($judged, $others)];
    }

    /**
     * @param list<FlaggedRound> $others
     *
     * @return list<RecurringP1Found>
     */
    private function recurrencesOf(FlaggedRound $round, array $others): array
    {
        $found = [];

        foreach ($round->places as $key => $finding) {
            if (array_any($others, static fn (FlaggedRound $earlier): bool => $earlier->flagsBefore($round, (string) $key))) {
                $found[] = new RecurringP1Found($round->id, $finding, (string) $key);
            }
        }

        return $found;
    }

    // Only a place no other earlier round of another head flagged, because a later round judged first recurred already when that earlier round was judged.
    /**
     * @param list<FlaggedRound> $others
     *
     * @return list<RecurringP1Found>
     */
    private function laterRecurrencesAgainst(FlaggedRound $judged, array $others): array
    {
        $found = [];

        foreach ($others as $later) {
            foreach ($later->places as $key => $finding) {
                if ($judged->flagsBefore($later, (string) $key) && !array_any($others, static fn (FlaggedRound $earlier): bool => $earlier->flagsBefore($later, (string) $key))) {
                    $found[] = new RecurringP1Found($later->id, $finding, (string) $key);
                }
            }
        }

        return $found;
    }
}
