<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Review\Contract\HeadIdentity;
use Intakt\Core\Review\Contract\IdentityAtHead;
use Intakt\Core\Review\Contract\RoundVerdicts;
use Intakt\Core\Review\Contract\Standing;
use Intakt\Core\Review\Contract\VerdictsOfRound;
use Intakt\Core\Watcher\Domain\StartedRound;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\OpenChange;
use LogicException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

// Review alone decides whether the verdicts of an earlier head still stand (T11), because it holds the round's recorded identity; the Watcher only records the answer.
final readonly class StaleVerdicts
{
    public function __construct(
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
        $current = $this->ask(new IdentityAtHead($project->startAt($open), new CommitId($round->head())), HeadIdentity::class);
        $verdicts = $this->ask(new VerdictsOfRound($round->roundId(), $current->identity, $current->move), RoundVerdicts::class);

        if (Standing::Stale === $verdicts->standing->standing) {
            $this->rounds->markStaleOnce($round, $this->ledger->verdictsStale(...));
        }
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $answer
     *
     * @return T
     */
    private function ask(object $query, string $answer): object
    {
        $result = $this->queries->dispatch($query)->last(HandledStamp::class)?->getResult();

        return $result instanceof $answer ? $result : throw new LogicException(sprintf('The query %s did not answer a %s.', $query::class, $answer));
    }
}
