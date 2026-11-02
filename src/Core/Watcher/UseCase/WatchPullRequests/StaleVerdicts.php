<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Review\Contract\ChangeDescription;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Core\Review\Contract\ReviewTerms;
use Intakt\Core\Review\Contract\RoundVerdicts;
use Intakt\Core\Review\Contract\Standing;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Core\Review\Contract\VerdictsOfRound;
use Intakt\Core\Watcher\Domain\StartedRound;
use Intakt\Plugin\Forge\Contract\Change;
use Intakt\Plugin\Forge\Contract\ChangedFile;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\Forge;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

// Asks Review whether the verdicts of a round on an earlier head still stand (T11); Review alone decides, the Watcher only records the answer.
final readonly class StaleVerdicts
{
    // The command a round is started with when the start names none, so the identity matches the one the round recorded.
    private const string CHECK_COMMAND = 'bin/check';

    public function __construct(
        private Forge $forge,
        private Panels $panels,
        #[Autowire(service: 'query.bus')]
        private MessageBusInterface $queries,
        private StartedRounds $rounds,
        private WatcherLedger $ledger,
    ) {
    }

    public function noteMovedHead(WatchedProject $project, OpenChange $open): void
    {
        foreach ($this->rounds->openRoundsBefore($project->name, $open) as $round) {
            $this->check($project, $open, $round);
        }
    }

    private function check(WatchedProject $project, OpenChange $open, StartedRound $round): void
    {
        $change = $this->forge->change($open->id);
        $query = new VerdictsOfRound((string) $round->roundId(), $this->currentIdentity($project, $change), $this->forge->headMoveSince(new CommitId($round->head()), $change));
        $verdicts = $this->queries->dispatch($query)->last(HandledStamp::class)?->getResult();

        if ($verdicts instanceof RoundVerdicts && Standing::Stale === $verdicts->standing->standing) {
            $this->rounds->markStale($round);
            $this->ledger->verdictsStale($project->name, $round->change(), (string) $round->roundId(), $round->head());
        }
    }

    private function currentIdentity(WatchedProject $project, Change $change): VerdictIdentity
    {
        $paths = array_map(static fn (ChangedFile $file): string => $file->path, [...$change->diff->files]);
        $description = new ChangeDescription($change->diff->addedLines() + $change->diff->removedLines(), $paths, []);

        return new VerdictIdentity($change->commits, ReviewTerms::of($project->task, self::CHECK_COMMAND, $this->panels->panelForChange($project->name, $description)));
    }
}
