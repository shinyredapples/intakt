<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Contract\RoundOpened;
use Symfony\Component\Uid\Uuid;

// Runs from the queue after the round is committed and publishes the round's latest state, not a fixed "0 of N", because RoundOpened shares its transport with the reviewers and one may have started first.
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
