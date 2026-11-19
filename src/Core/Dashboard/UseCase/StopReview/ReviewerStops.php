<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\StopReview;

use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\ReviewerProgress;
use Intakt\Core\Dashboard\Domain\ReviewState;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\RoundLock;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use NoDiscard;
use Symfony\Component\Lock\LockInterface;

final readonly class ReviewerStops
{
    private const string FLASH_SUCCESS = 'success';
    private const string FLASH_ERROR = 'error';

    public function __construct(
        private LedgerReviewPages $reviews,
        private Ledger $ledger,
        private RoundLock $rounds,
    ) {
    }

    /**
     * @return array{string, string}|null
     */
    // Null when the ledger shows no running reviewer with this id in an unjudged round, so the caller answers that nothing could be stopped.
    #[NoDiscard('the flash line is the only report of the press')]
    public function requestStopOf(string $round, string $reviewer): ?array
    {
        if (!in_array($reviewer, $this->runningReviewersOf($round) ?? [], true)) {
            return null;
        }

        // Taken only now, for a round the ledger knows, with the key of the start press, so a start and a stop of one round never run at once.
        $lock = $this->rounds->tryHolding($round);

        if (!$lock instanceof LockInterface) {
            return [self::FLASH_ERROR, 'Another press for this round is still running, so nothing was stopped.'];
        }

        try {
            return $this->recorded($round, $reviewer)
                ? [self::FLASH_SUCCESS, 'Asked the reviewer to stop.']
                : [self::FLASH_ERROR, 'The stop request could not be recorded in the ledger.'];
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{string, string}|null
     */
    // Null when the ledger shows no unjudged round with this id, so the caller answers that there is no such round to stop.
    #[NoDiscard('the flash line is the only report of the press')]
    public function requestStopOfRound(string $round): ?array
    {
        $running = $this->runningReviewersOf($round);

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
    // Null when the round is unknown or judged, so nothing in it can be stopped any more.
    private function runningReviewersOf(string $round): ?array
    {
        $reviewed = array_find($this->reviews->reviewOf($round)->rounds ?? [], static fn (ReviewedRound $candidate): bool => $candidate->round === $round);

        if (!$reviewed instanceof ReviewedRound || ReviewState::Judged === $reviewed->state) {
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
