<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\PublishVerdict;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Contract\PublishHandedOverChange;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Symfony\Component\Messenger\MessageBusInterface;

// Queued and not published here, because the watcher sends it inside its tick's transaction, and the forge call belongs to a worker that sees the hand-over committed.
#[AsCommandHandler]
final readonly class PublishHandedOverChangeHandler
{
    public function __construct(
        private ReviewRounds $rounds,
        private MessageBusInterface $commands,
    ) {
    }

    // Only the latest judged round, because its head is the one the pull request stands at, and the publication itself drops a round a later one of its head replaced.
    public function __invoke(PublishHandedOverChange $command): void
    {
        $round = $this->rounds->latestJudgedOf($command->project, $command->change);

        if ($round instanceof ReviewRound) {
            $this->commands->dispatch(new PublishVerdict($round->id()->toRfc4122()));
        }
    }
}
