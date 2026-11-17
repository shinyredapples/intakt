<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\ReviewerStarted;
use Intakt\Core\Review\Contract\RoundCosts;
use Intakt\Core\Review\Contract\RoundEndings;

final readonly class LedgerReviewPages
{
    public function __construct(
        private Ledger $ledger,
        private RoundPayloads $payloads,
        private RoundCosts $costs,
        private ChangeDecision $decision,
        private FailureInWords $failures,
        private RoundEndings $endings,
        private ReplayMarks $marks,
    ) {
    }

    // Null for a round the ledger never started readably, so the page answers 404 instead of an empty review.
    public function reviewOf(string $round): ?Review
    {
        return $this->rounds($this->ledger->readInOrder())->reviewOf($round);
    }

    // Null on a fresh install, because then no review has run.
    public function firstReview(): ?Review
    {
        return array_first($this->rounds($this->ledger->readInOrder())->reviews());
    }

    // The review whose first round started last, because a project may hold the reviews of more than one change.
    public function latestReviewOf(string $project): ?Review
    {
        return array_last(array_filter($this->rounds($this->ledger->readInOrder())->reviews(), static fn (Review $review): bool => $review->project === $project));
    }

    /**
     * @return list<ReviewedRound>
     */
    public function roundsInStartOrder(): array
    {
        return $this->rounds($this->ledger->readInOrder())->inStartOrder();
    }

    // Read from the starts alone, because a live update goes out for each streamed text and must not read the whole ledger; the latest start wins, as on the page, and null means the reviewer has not started in that round.
    public function startedReviewerOf(string $round, string $reviewer): ?ReviewerOutcome
    {
        $started = null;

        foreach ($this->ledger->readOfKind(LedgerEventKind::ReviewerStarted) as $recorded) {
            $row = $this->payloads->reviewerRowOf($recorded);

            if ($row instanceof ReviewerStarted && $row->round === $round && $row->reviewer === $reviewer) {
                $started = new ReviewerOutcome($row->reviewer, $row->role, $row->tier->value, $recorded->recordedAt, $row->model);
            }
        }

        return $started;
    }

    public function board(): Board
    {
        return $this->boardOf($this->ledger->readInOrder());
    }

    public function boardOf(RecordedEvents $events): Board
    {
        return new Board($this->rounds($events)->reviews());
    }

    private function rounds(RecordedEvents $events): LedgerRounds
    {
        $rounds = new LedgerRounds($this->payloads, $this->costs, $this->decision, $this->failures, $this->endings, $this->marks);

        foreach ($events as $recorded) {
            $rounds->add($recorded);
        }

        return $rounds;
    }
}
