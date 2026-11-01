<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\JudgedFindings;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;

final readonly class JudgedRounds
{
    public function __construct(
        private ReviewRounds $rounds,
        private StoredFindings $findings,
        private RoundLedger $ledger,
    ) {
    }

    // Null for a round judged already, so a redelivered message asks the model nothing.
    public function toJudge(string $roundId): ?ReviewRound
    {
        $round = $this->rounds->stored($roundId);

        return RoundState::Judged === $round->state() ? null : $round;
    }

    /**
     * @return list<Finding>
     */
    public function findingsOf(ReviewRound $round): array
    {
        return $this->findings->openReportedIn($round);
    }

    // Checked again under the round's lock, because another worker may have judged the round while this one waited for the model.
    public function record(ReviewRound $round, JudgedFindings $judged): void
    {
        $this->rounds->withLockedRound($round->id(), function (ReviewRound $locked) use ($judged): void {
            if ($this->findings->markJudged($locked)) {
                $this->ledger->judgeDone($locked, $judged);
            }
        });
    }
}
