<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\StopReview;

use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\ReviewerProgress;
use Intakt\Core\Dashboard\Domain\ReviewState;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
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
    ) {
    }

    /**
     * @return array{string, string}|null
     */
    // Null when the ledger shows no running reviewer with this id in an unjudged round, so the caller answers that nothing could be stopped.
    #[NoDiscard('the flash line is the only report of the press')]
    public function requestStopOf(string $round, string $reviewer): ?array
    {
        if (!in_array($reviewer, $this->runningReviewersOf($round), true)) {
            return null;
        }

        return $this->recorded($round, $reviewer)
            ? [self::FLASH_SUCCESS, 'Asked the reviewer to stop.']
            : [self::FLASH_ERROR, 'The stop request could not be recorded in the ledger.'];
    }

    /**
     * @return array{string, string}|null
     */
    // Null when the ledger shows no running reviewer in this unjudged round, so the caller answers that nothing could be stopped.
    #[NoDiscard('the flash line is the only report of the press')]
    public function requestStopOfRound(string $round): ?array
    {
        $running = $this->runningReviewersOf($round);

        if ([] === $running) {
            return null;
        }

        // Every request is tried, so one refused append does not leave the other reviewers running.
        $refused = array_filter($running, fn (string $reviewer): bool => !$this->recorded($round, $reviewer));

        return [] === $refused
            ? [self::FLASH_SUCCESS, sprintf('Asked %d running %s to stop.', count($running), 1 === count($running) ? 'reviewer' : 'reviewers')]
            : [self::FLASH_ERROR, sprintf('%d of %d stop requests could not be recorded in the ledger.', count($refused), count($running))];
    }

    /**
     * @return list<string>
     */
    private function runningReviewersOf(string $round): array
    {
        $reviewed = array_find($this->reviews->reviewOf($round)->rounds ?? [], static fn (ReviewedRound $candidate): bool => $candidate->round === $round);

        if (!$reviewed instanceof ReviewedRound || ReviewState::Judged === $reviewed->state) {
            return [];
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
