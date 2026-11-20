<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\JudgeDone;
use Intakt\Core\Review\Contract\RecurringP1Found;
use Intakt\Core\Review\Domain\FlaggedRound;
use Intakt\Core\Review\Domain\RecurringP1;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;

// Asked in the transaction that settles a round (its judging, or the record of its judging failed for good) under the round's lock and the change's lock, so a redelivered message, which finds the round settled, finds nothing twice, and two rounds of the change settled at once never miss each other.
final readonly class RecurringP1s
{
    public function __construct(
        private ReviewRounds $rounds,
        private Ledger $ledger,
        private LedgerPayloads $payloads,
        private FailedJudgings $failedJudgings,
    ) {
    }

    /**
     * @return list<RecurringP1Found>
     */
    public function foundWhenJudged(ReviewRound $round, JudgeDone $done): array
    {
        $head = $round->headHash();

        // Flags nothing, because a round stored before identities were recorded names no head to compare.
        return $this->toRecordWith($round, null === $head ? [] : [new FlaggedRound($round->number(), $head, $done)]);
    }

    // The failed round no longer waits for a judging, so the recurrences it held back are decided now.
    /**
     * @return list<RecurringP1Found>
     */
    public function foundWhenJudgingFailed(ReviewRound $round): array
    {
        return $this->toRecordWith($round, []);
    }

    /**
     * @param list<FlaggedRound> $settled
     *
     * @return list<RecurringP1Found>
     */
    private function toRecordWith(ReviewRound $round, array $settled): array
    {
        $others = $this->otherRoundsWithAHead($round);
        $judged = array_filter($others, static fn (ReviewRound $other): bool => RoundState::Judged === $other->state());

        return new RecurringP1()->toRecord([...$this->flagged($judged), ...$settled], $this->waiting($others), $this->recorded($judged));
    }

    // One scan of the judge_done events for all other rounds, because it runs while the change's lock is held.
    /**
     * @param array<string, ReviewRound> $judged
     *
     * @return list<FlaggedRound>
     */
    private function flagged(array $judged): array
    {
        $flagged = [];

        foreach ($this->ledger->readOfKind(LedgerEventKind::JudgeDone) as $recorded) {
            $done = $this->payloads->of($recorded, JudgeDone::class);
            $other = null === $done ? null : ($judged[$done->round] ?? null);

            if (null !== $done && null !== $other) {
                $flagged[] = new FlaggedRound($other->number(), (string) $other->headHash(), $done);
                unset($judged[$done->round]);
            }
        }

        return $flagged;
    }

    // Only a complete round waits for its judging, which ends judged or failed for good, and both settle what it held back; a round still collecting may never complete, so it holds nothing back.
    /**
     * @param array<string, ReviewRound> $others
     *
     * @return list<int>
     */
    private function waiting(array $others): array
    {
        $complete = array_filter($others, static fn (ReviewRound $other): bool => RoundState::Complete === $other->state());
        $failed = [] === $complete ? [] : $this->failedJudgings->recordedRounds();

        return array_values(array_map(static fn (ReviewRound $other): int => $other->number(), array_diff_key($complete, $failed)));
    }

    // Only the change's judged rounds, because only a judged round recurs.
    /**
     * @param array<string, ReviewRound> $judged
     *
     * @return list<RecurringP1Found>
     */
    private function recorded(array $judged): array
    {
        $recorded = [];

        foreach ($this->ledger->readOfKind(LedgerEventKind::RecurringP1Found) as $event) {
            $found = $this->payloads->of($event, RecurringP1Found::class);

            if (null !== $found && isset($judged[$found->round])) {
                $recorded[] = $found;
            }
        }

        return $recorded;
    }

    /**
     * @return array<string, ReviewRound>
     */
    private function otherRoundsWithAHead(ReviewRound $round): array
    {
        $others = [];

        foreach ($this->rounds->otherRoundsOf($round) as $other) {
            if (null !== $other->headHash()) {
                $others[$other->id()->toRfc4122()] = $other;
            }
        }

        return $others;
    }
}
