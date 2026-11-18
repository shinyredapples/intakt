<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\JudgedFindings;
use Intakt\Core\Review\Contract\RecurringP1Found;
use Intakt\Core\Review\Domain\RecurringP1;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Infrastructure\FindingKeys;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;

// Called in the judging's transaction on the round's lock, so a redelivered judging, which finds the round judged, records no recurrence twice. Each P1 of a round at a place is recorded once, at the moment it and a first earlier round on another head that flagged a P1 there are both judged, whichever of the two is judged last, paired with the newest such earlier round judged by then; an earlier round judged afterwards never changes the pair.
final readonly class RecurringP1s
{
    public function __construct(
        private ReviewRounds $rounds,
        private StoredFindings $findings,
        private RoundLedgerReading $reading,
        private FindingKeys $keys,
        private RoundLedger $ledger,
    ) {
    }

    public function recordFor(ReviewRound $round, JudgedFindings $judged): void
    {
        // On the change's lock, because two rounds of the change judged at once would each miss the other.
        $this->findings->lockChangeOf($round);
        $judgedNow = new FlaggedRound($round, $judged->actList);
        $others = array_map(fn (ReviewRound $other): FlaggedRound => new FlaggedRound($other, $this->actListOf($other)), $this->rounds->judgedBesides($round));

        $this->recordRecurrencesOf($judgedNow, $others);

        foreach ($others as $later) {
            if ($judgedNow->isBeforeOnAnotherHead($later)) {
                $this->recordFirstRecurrencesIn($later, $judgedNow, $others);
            }
        }
    }

    /**
     * @param list<FlaggedRound> $others
     */
    private function recordRecurrencesOf(FlaggedRound $round, array $others): void
    {
        $earlier = array_reverse(array_values(array_filter($others, static fn (FlaggedRound $other): bool => $other->isBeforeOnAnotherHead($round))));
        $unpaired = $round->act;

        foreach ($earlier as $before) {
            $pairs = new RecurringP1()->in($before->act, $unpaired);

            foreach ($pairs as $pair) {
                $this->ledger->recurringP1Found($this->found($round, $before, $pair));
            }

            $unpaired = $this->withoutPlacesOf($unpaired, $pairs);
        }
    }

    // A later round already judged was paired when it was judged, or when an earlier round at its place was; only a P1 no other earlier round of another head flagged is recorded now, so it is recorded once.
    /**
     * @param list<FlaggedRound> $others
     */
    private function recordFirstRecurrencesIn(FlaggedRound $later, FlaggedRound $judgedNow, array $others): void
    {
        $alsoEarlier = array_filter($others, static fn (FlaggedRound $other): bool => $other !== $later && $other->isBeforeOnAnotherHead($later));

        foreach (new RecurringP1()->in($judgedNow->act, $later->act) as $pair) {
            if (!array_any($alsoEarlier, static fn (FlaggedRound $other): bool => [] !== new RecurringP1()->in($other->act, [$pair[0]]))) {
                $this->ledger->recurringP1Found($this->found($later, $judgedNow, $pair));
            }
        }
    }

    /**
     * @param list<Finding>                 $findings
     * @param list<array{Finding, Finding}> $pairs
     *
     * @return list<Finding>
     */
    private function withoutPlacesOf(array $findings, array $pairs): array
    {
        $rule = new RecurringP1();
        $paired = array_map(static fn (array $pair): ?string => $rule->placeOf($pair[0]), $pairs);

        return array_values(array_filter($findings, static fn (Finding $finding): bool => !in_array($rule->placeOf($finding), $paired, true)));
    }

    // The act findings of the round as its judge_done recorded their ids, one by one, so a finding dropped before its judge never counts as flagged.
    /**
     * @return list<Finding>
     */
    private function actListOf(ReviewRound $round): array
    {
        $actIds = $this->reading->actIdsOf($round->id()->toRfc4122());

        return array_values(array_filter($this->findings->reportedIn($round), static fn (Finding $finding): bool => in_array($finding->id, $actIds, true)));
    }

    // Keyed at the recurring round's head, because that is the code an escalation of the finding would judge.
    /**
     * @param array{Finding, Finding} $pair
     */
    private function found(FlaggedRound $round, FlaggedRound $earlier, array $pair): RecurringP1Found
    {
        [$finding, $before] = $pair;

        return new RecurringP1Found(
            $round->round->id()->toRfc4122(),
            $round->round->number(),
            $finding->id,
            $earlier->round->id()->toRfc4122(),
            $earlier->round->number(),
            $before->id,
            (string) $finding->file,
            $finding->symbol,
            $this->keys->keyOf($finding, $round->round->checkoutRoot()),
        );
    }
}
