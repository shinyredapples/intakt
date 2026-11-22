<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerProgress;
use Intakt\Core\Review\Contract\FinishedState;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Contract\RoundUpdates;
use Symfony\Component\Uid\Uuid;

// The judged state a live round pushes, rendered from the replayed rows with today's templates, because a recording keeps the page updates of the templates it was made with (#3470).
final readonly class ReplayedJudgedState
{
    public function __construct(
        private LedgerReviewPages $pages,
        private RoundUpdates $rounds,
    ) {
    }

    // Nothing for a round the rows do not show judged, because a page must not show a call the ledger does not hold.
    public function publish(string $round): void
    {
        if (!Uuid::isValid($round)) {
            return;
        }

        $reviewed = array_find($this->pages->reviewOf($round)?->rounds ?? [], static fn (ReviewedRound $candidate): bool => $candidate->round === $round);

        if (!$reviewed instanceof ReviewedRound || !$reviewed->isJudged()) {
            return;
        }

        $this->rounds->roundChanged(Uuid::fromString($round), self::progressOf($reviewed));
    }

    // From the reviewer rows a reload shows, because a replayed round has no stored round to read its progress from.
    private static function progressOf(ReviewedRound $reviewed): RoundProgress
    {
        $finished = [];

        foreach ($reviewed->outcomes as $outcome) {
            if (ReviewerProgress::Running !== $outcome->progress) {
                $finished[$outcome->id] = FinishedState::from($outcome->progress->value);
            }
        }

        return new RoundProgress(count($finished), max($reviewed->reviewers, count($reviewed->outcomes)), true, $finished, count($reviewed->outcomes));
    }
}
