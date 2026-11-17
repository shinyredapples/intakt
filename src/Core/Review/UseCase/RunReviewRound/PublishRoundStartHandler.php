<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Contract\RoundOpened;
use Symfony\Component\Uid\Uuid;

// Runs from the queue after the round is committed and publishes the round's latest state, so a page opened before any reviewer is read has counts from the round state; a reviewer that started first is already counted.
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
