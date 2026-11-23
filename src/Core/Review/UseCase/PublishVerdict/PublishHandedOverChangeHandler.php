<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\PublishVerdict;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Contract\PublishHandedOverChange;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Symfony\Component\Messenger\MessageBusInterface;

// Run by a worker from the queue, never inside the watch tick, because a judging that commits while the tick is open checks the hand-over before the tick commits it, so only a lookup after that commit sees the judged round.
#[AsCommandHandler]
final readonly class PublishHandedOverChangeHandler
{
    public function __construct(
        private ReviewRounds $rounds,
        private MessageBusInterface $commands,
    ) {
    }

    // Only a round of the head the pull request stood at, because a later head's judging sees the hand-over and publishes itself, and the publication drops a round a later one of its head replaced.
    public function __invoke(PublishHandedOverChange $command): void
    {
        $round = $this->rounds->latestJudgedAt($command->project, $command->change, $command->head);

        if ($round instanceof ReviewRound) {
            $this->commands->dispatch(new PublishVerdict($round->id()->toRfc4122()));
        }
    }
}
