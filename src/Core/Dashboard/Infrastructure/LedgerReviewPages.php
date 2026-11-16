<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\FailureInWords;
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

    /**
     * @return list<ReviewedRound>
     */
    public function roundsInStartOrder(): array
    {
        return $this->rounds($this->ledger->readInOrder())->inStartOrder();
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
