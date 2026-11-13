<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\MarkVerdictsStale;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Contract\MarkVerdictsStale;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;

// Written under the round's row lock, because a reviewer's worker stores its verdict on the same round at the same time.
#[AsCommandHandler]
final readonly class MarkVerdictsStaleHandler
{
    public function __construct(private ReviewRounds $rounds)
    {
    }

    public function __invoke(MarkVerdictsStale $command): void
    {
        $round = $this->rounds->stored($command->roundId);

        $this->rounds->withLockedRound($round->id(), static function (ReviewRound $locked): void {
            $locked->markVerdictsStale();
        });
    }
}
