<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Review\Contract\DroppedFinding;
use Intakt\Core\Review\Contract\JudgedFindings;
use Intakt\Core\Review\Domain\QuoteCheck;
use Intakt\Core\Review\Domain\QuotesInChange;
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
        private ChangeText $changeText,
    ) {
    }

    // Null for a round judged already, so a redelivered message asks the model nothing.
    public function toJudge(string $roundId): ?ReviewRound
    {
        $round = $this->rounds->stored($roundId);

        return RoundState::Judged === $round->state() ? null : $round;
    }

    // Checked before the judge, so a finding whose every quote is code the change does not hold is never judged, never on the act list and never merged with a real one.
    public function checkedFindingsOf(ReviewRound $round): QuoteCheck
    {
        return new QuotesInChange()->check($this->findings->reportedIn($round), $this->changeText->of($round->checkoutRoot(), $round->diff()));
    }

    // Checked again under the round's lock, because another worker may have judged the round while this one waited for the model.
    /**
     * @param list<DroppedFinding> $dropped
     */
    public function record(ReviewRound $round, JudgedFindings $judged, array $dropped): void
    {
        $this->rounds->withLockedRound($round->id(), function (ReviewRound $locked) use ($judged, $dropped): void {
            if ($this->findings->markJudged($locked)) {
                $this->ledger->judgeDone($locked, $judged, $dropped);
            }
        });
    }
}
