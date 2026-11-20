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

// Asked in the judging's transaction under the round's lock and the change's lock, so a redelivered judging, which finds the round judged, finds nothing twice, and two rounds of the change judged at once never miss each other.
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

        // None, because a round stored before identities were recorded names no head to compare.
        if (null === $head) {
            return [];
        }

        $others = $this->otherRoundsWithAHead($round);
        $judged = array_filter($others, static fn (ReviewRound $other): bool => RoundState::Judged === $other->state());

        return new RecurringP1()->foundWhenJudged(new FlaggedRound($round->number(), $head, $done), $this->flagged($judged), $this->pending(array_diff_key($others, $judged)));
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

    // The numbers of the rounds that may still be judged; a round whose judging failed for good never will be, so it holds no recurrence back.
    /**
     * @param array<string, ReviewRound> $unjudged
     *
     * @return list<int>
     */
    private function pending(array $unjudged): array
    {
        $pending = [];

        foreach ($unjudged as $id => $other) {
            if (!$this->failedJudgings->isRecorded($id)) {
                $pending[] = $other->number();
            }
        }

        return $pending;
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
