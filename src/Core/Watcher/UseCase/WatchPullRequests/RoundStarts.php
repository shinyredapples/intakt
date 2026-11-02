<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Watcher\Domain\HeadDecision;
use Intakt\Plugin\Forge\Contract\OpenChange;
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

    // Queues the round before the ledger line, so a ledger that is down cannot cost a round that the record then hides.
    public function startFor(WatchedProject $project, HeadDecision $decision): void
    {
        $round = $this->rounds->startOnce($project->name, $decision->change, fn (): string => $this->queue($project, $decision->change));

        if (null !== $round) {
            $this->ledger->roundQueued($project->name, $decision, $round);
        }
    }

    private function queue(WatchedProject $project, OpenChange $green): string
    {
        $roundId = $this->commands->dispatch($project->startAt($green))->last(HandledStamp::class)?->getResult();

        return is_string($roundId) ? $roundId : throw new RuntimeException('The review start did not answer the id of the round.');
    }
}
