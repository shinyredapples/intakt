<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\StopReviewer;

use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\ReviewerProgress;
use Intakt\Core\Dashboard\Domain\ReviewState;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;

final readonly class ReviewerStops
{
    public function __construct(
        private LedgerReviewPages $reviews,
        private Ledger $ledger,
    ) {
    }

    /**
     * @return array{string, string}|null
     */
    // Null unless the ledger shows this reviewer running in an unjudged round, so the caller answers that there is nothing to stop; the reviewer's own process cancels its run when it finds the request.
    public function request(string $round, string $reviewer): ?array
    {
        $outcome = $this->runningReviewer($round, $reviewer);

        if (!$outcome instanceof ReviewerOutcome) {
            return null;
        }

        $payload = json_encode(['round' => $round, 'reviewer' => $reviewer], JSON_THROW_ON_ERROR);
        $appended = $this->ledger->append(new LedgerEvent(LedgerEventKind::ReviewerStopRequested, $payload));

        return $appended->succeeded() ? ['success', 'Asked the '.$outcome->role.' reviewer to stop.'] : ['error', 'The stop request could not be recorded.'];
    }

    private function runningReviewer(string $round, string $reviewer): ?ReviewerOutcome
    {
        $review = $this->reviews->reviewOf($round);
        $reviewed = $review instanceof Review ? array_find($review->rounds, static fn (ReviewedRound $candidate): bool => $candidate->round === $round) : null;

        if (!$reviewed instanceof ReviewedRound || ReviewState::Judged === $reviewed->state) {
            return null;
        }

        return array_find($reviewed->outcomes, static fn (ReviewerOutcome $outcome): bool => $outcome->id === $reviewer && ReviewerProgress::Running === $outcome->progress);
    }
}
