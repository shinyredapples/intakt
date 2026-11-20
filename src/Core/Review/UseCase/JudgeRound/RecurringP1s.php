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
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;

// Asked in the judging's transaction under the round's lock and the change's lock, so a redelivered judging, which finds the round judged, finds nothing twice, and two rounds of the change judged at once never miss each other.
final readonly class RecurringP1s
{
    public function __construct(
        private ReviewRounds $rounds,
        private Ledger $ledger,
        private LedgerPayloads $payloads,
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

        return new RecurringP1()->foundWhenJudged(new FlaggedRound($round->number(), $head, $done), $this->flagged($round));
    }

    // One scan of the judge_done events for all other rounds, because it runs while the change's lock is held.
    /**
     * @return list<FlaggedRound>
     */
    private function flagged(ReviewRound $round): array
    {
        $others = $this->otherRoundsWithAHead($round);
        $flagged = [];

        foreach ($this->ledger->readOfKind(LedgerEventKind::JudgeDone) as $recorded) {
            $done = $this->payloads->of($recorded, JudgeDone::class);
            $other = null === $done ? null : ($others[$done->round] ?? null);

            if (null !== $done && null !== $other) {
                $flagged[] = new FlaggedRound($other->number(), (string) $other->headHash(), $done);
                unset($others[$done->round]);
            }
        }

        return $flagged;
    }

    /**
     * @return array<string, ReviewRound>
     */
    private function otherRoundsWithAHead(ReviewRound $round): array
    {
        $others = [];

        foreach ($this->rounds->otherJudgedRoundsOf($round) as $other) {
            if (null !== $other->headHash()) {
                $others[$other->id()->toRfc4122()] = $other;
            }
        }

        return $others;
    }
}
