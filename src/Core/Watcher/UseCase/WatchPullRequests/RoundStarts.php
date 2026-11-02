<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\StartReview;
use Intakt\Core\Watcher\Domain\HeadDecision;
use RuntimeException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final readonly class RoundStarts
{
    public function __construct(
        private MessageBusInterface $commands,
        private StartedRounds $rounds,
        private WatcherLedger $ledger,
    ) {
    }

    // Queues the round before the ledger line, so a ledger that is down cannot cost a round that the claim then hides.
    public function startFor(WatchedProject $project, HeadDecision $decision): void
    {
        $round = $this->rounds->startOnce($project->name, $decision->change, fn (): string => $this->queue($project, $decision));

        if (null !== $round) {
            $this->ledger->roundQueued($project->name, $decision, $round);
        }
    }

    private function queue(WatchedProject $project, HeadDecision $decision): string
    {
        $round = new RequestedRound($decision->change->id->value, null, new ChangeSource($project->repository, $project->base));
        $handled = $this->commands->dispatch(new StartReview($project->name, $project->task, $round))->last(HandledStamp::class);
        $roundId = $handled?->getResult();

        return is_string($roundId) ? $roundId : throw new RuntimeException('The review start did not answer the id of the round.');
    }
}
