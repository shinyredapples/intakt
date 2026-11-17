<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Contract\RoundOpened;
use Symfony\Component\Uid\Uuid;

// Runs from the queue after the round is committed, so the page opened before any reviewer starts already reads "0 of N" from the round state.
#[AsCommandHandler]
final readonly class PublishRoundStartHandler
{
    public function __construct(
        private LiveRoundProgress $progress,
    ) {
    }

    public function __invoke(RoundOpened $opened): void
    {
        $this->progress->publishLatest(Uuid::fromString($opened->round));
    }
}
