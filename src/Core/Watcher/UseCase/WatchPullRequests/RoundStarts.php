<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Review\Contract\QueuedRound;
use Intakt\Core\Watcher\Domain\HeadDecision;
use Intakt\Plugin\Forge\Contract\ChangeLabels;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Plugin\Forge\Contract\RoundStatusMirror;
use RuntimeException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final readonly class RoundStarts
{
    public function __construct(
        private MessageBusInterface $commands,
        private StartedRounds $rounds,
        private WatcherLedger $ledger,
        private ChangeLabels $labels,
        private RoundStatusMirror $status,
    ) {
    }

    // Queues the round before the ledger line, so a ledger that is down cannot cost a round; the stored round owes its line until the ledger takes it. The status label is reconciled after the commit, so a round rolled back never shows as in review, and also when the head's round already runs, because a person's change of the label makes the head due again.
    public function startFor(WatchedProject $project, HeadDecision $decision): void
    {
        $this->rounds->startOnce($project->name, $decision->change, fn (): QueuedRound => $this->queue($project, $decision->change));
        $this->status->reconcile($project->name, $decision->change->id);
        $this->recordQueued($project->name);
    }

    // For a head that starts no round now (its CI turned pending or red, or its boot failed) on a pull request that had a round at this or an earlier head, because the person's change of the status label that made it due again is owed a restore all the same.
    public function restoreIfStarted(WatchedProject $project, OpenChange $change): void
    {
        if ($this->rounds->hasRoundOf($project->name, $change)) {
            $this->status->reconcile($project->name, $change->id);
        }
    }

    // Every round of the project still owing its line, so a line whose append failed is written by a later tick even when no head is due.
    public function recordQueued(string $project): void
    {
        foreach ($this->rounds->notYetRecorded($project) as $round) {
            $this->rounds->recordOnce($round, $this->ledger->roundQueued(...));
        }
    }

    private function queue(WatchedProject $project, OpenChange $green): QueuedRound
    {
        $labels = $this->labels->steeringOf($project->name, $green);
        $queued = $this->commands->dispatch($project->startAt($green, $labels))->last(HandledStamp::class)?->getResult();

        return $queued instanceof QueuedRound ? $queued : throw new RuntimeException('The review start did not answer the queued round.');
    }
}
