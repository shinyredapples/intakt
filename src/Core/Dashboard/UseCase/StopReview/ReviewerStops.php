<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\StopReview;

use Intakt\Core\Dashboard\Domain\ReviewedRound;
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
        if (!$this->isRunning($round, $reviewer)) {
            return null;
        }

        $payload = json_encode(['round' => $round, 'reviewer' => $reviewer], JSON_THROW_ON_ERROR);
        $result = $this->ledger->append(new LedgerEvent(LedgerEventKind::ReviewerStopRequested, $payload));

        return $result->succeeded()
            ? [self::FLASH_SUCCESS, 'Asked the reviewer to stop.']
            : [self::FLASH_ERROR, 'The stop request could not be recorded in the ledger.'];
    }

    private function isRunning(string $round, string $reviewer): bool
    {
        $reviewed = array_find($this->reviews->reviewOf($round)->rounds ?? [], static fn (ReviewedRound $candidate): bool => $candidate->round === $round);

        if (!$reviewed instanceof ReviewedRound || ReviewState::Judged === $reviewed->state) {
            return false;
        }

        foreach ($reviewed->outcomes as $outcome) {
            if ($outcome->id === $reviewer) {
                return ReviewerProgress::Running === $outcome->progress;
            }
        }

        return false;
    }
}
