<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Review\Contract\QueuedRound;
use Intakt\Core\Watcher\Domain\HeadDecision;
use Intakt\Plugin\Forge\Contract\ChangeLabels;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Plugin\Forge\Contract\RoundStatusLabel;
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

    // Queues the round before the ledger line, so a ledger that is down cannot cost a round; the stored round owes its line until the ledger takes it.
    public function startFor(WatchedProject $project, HeadDecision $decision): void
    {
        $queued = $this->rounds->startOnce($project->name, $decision->change, fn (): QueuedRound => $this->queue($project, $decision->change));
        $this->mirrorStatus($project, $decision->change, $queued);
        $this->recordQueued($project->name);
    }

    // For a head that starts no round now (its CI turned pending or red, or its boot failed) on a pull request that had a round at this or an earlier head, because the person's change of the status label that made it due again is owed a restore all the same.
    public function restoreIfStarted(WatchedProject $project, OpenChange $change): void
    {
        if ($this->rounds->hasRoundOf($project->name, $change)) {
            $this->status->restore($project->name, $change->id);
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

    // After the commit, so a round rolled back never shows as in review; a head whose round already runs is due again because the pull request changed, which a person's change of the status label does, so the status Intakt set last is put back.
    private function mirrorStatus(WatchedProject $project, OpenChange $change, ?QueuedRound $queued): void
    {
        if ($queued instanceof QueuedRound) {
            $this->status->show($project->name, $change->id, RoundStatusLabel::InReview);

            return;
        }

        $this->status->restore($project->name, $change->id);
    }
}
