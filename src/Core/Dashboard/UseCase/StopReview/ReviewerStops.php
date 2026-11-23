<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\StopReview;

use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\ReviewerProgress;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\RoundLock;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use NoDiscard;

final readonly class ReviewerStops
{
    private const string FLASH_SUCCESS = 'success';
    private const string FLASH_ERROR = 'error';

    public function __construct(
        private LedgerReviewPages $reviews,
        private Ledger $ledger,
        private RoundLock $locks,
    ) {
    }

    /**
     * @return array{string, string}|null
     */
    // Null when the ledger shows no running reviewer with this id in a round still in review, so the caller answers that nothing could be stopped.
    #[NoDiscard('the flash line is the only report of the press')]
    public function requestStopOf(string $round, string $reviewer): ?array
    {
        $review = $this->reviews->reviewOf($round);

        if (!$review instanceof Review || !in_array($reviewer, $this->runningReviewersOf($review, $round) ?? [], true)) {
            return null;
        }

        // Taken only now, for a round the ledger knows, with the key of the start press (the review's first round), so a start and a stop of one review never run at once; RoundLock releases it once the answer is built.
        if (!$this->locks->tryHolding($review->firstRound())) {
            return [self::FLASH_ERROR, 'Another press for this review is still running, so nothing was stopped.'];
        }

        return $this->recorded($round, $reviewer)
            ? [self::FLASH_SUCCESS, 'Asked the reviewer to stop.']
            : [self::FLASH_ERROR, 'The stop request could not be recorded in the ledger.'];
    }

    /**
     * @return array{string, string}|null
     */
    // Null when the ledger shows no round still in review with this id, so the caller answers that there is no such round to stop.
    #[NoDiscard('the flash line is the only report of the press')]
    public function requestStopOfRound(string $round): ?array
    {
        $running = $this->runningReviewersOf($this->reviews->reviewOf($round), $round);

        if (null === $running) {
            return null;
        }

        // An error line rather than a 404, because the page offers Stop for the whole round in review (also before the first reviewer starts and while the judge works) and a stop request can only reach a reviewer that started.
        if ([] === $running) {
            return [self::FLASH_ERROR, 'No reviewer runs in this round right now, so nothing was stopped.'];
        }

        // Every request is tried, so one refused append does not leave the other reviewers running.
        $refused = array_filter($running, fn (string $reviewer): bool => !$this->recorded($round, $reviewer));

        return [] === $refused
            ? [self::FLASH_SUCCESS, sprintf('Asked %d running %s to stop.', count($running), 1 === count($running) ? 'reviewer' : 'reviewers')]
            : [self::FLASH_ERROR, sprintf('%d of %d stop requests could not be recorded in the ledger.', count($refused), count($running))];
    }

    /**
     * @return list<string>|null
     */
    // Null when the round is unknown, judged or ended without the judge's call, the same rounds whose page hides the Stop forms, so nothing in it can be stopped any more.
    private function runningReviewersOf(?Review $review, string $round): ?array
    {
        $reviewed = array_find($review->rounds ?? [], static fn (ReviewedRound $candidate): bool => $candidate->round === $round);

        if (!$reviewed instanceof ReviewedRound || $reviewed->isJudged() || $reviewed->hasEnded()) {
            return null;
        }

        $running = array_filter($reviewed->outcomes, static fn (ReviewerOutcome $outcome): bool => ReviewerProgress::Running === $outcome->progress);

        return array_values(array_map(static fn (ReviewerOutcome $outcome): string => $outcome->id, $running));
    }

    private function recorded(string $round, string $reviewer): bool
    {
        $payload = json_encode(['round' => $round, 'reviewer' => $reviewer], JSON_THROW_ON_ERROR);

        return $this->ledger->append(new LedgerEvent(LedgerEventKind::ReviewerStopRequested, $payload))->succeeded();
    }
}
