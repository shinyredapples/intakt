<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Review\Contract\HeadIdentity;
use Intakt\Core\Review\Contract\IdentityAtHead;
use Intakt\Core\Review\Contract\Labels;
use Intakt\Core\Review\Contract\MarkVerdictsStale;
use Intakt\Core\Review\Contract\RoundVerdicts;
use Intakt\Core\Review\Contract\Standing;
use Intakt\Core\Review\Contract\VerdictsOfRound;
use Intakt\Core\Watcher\Domain\FailedBoot;
use Intakt\Core\Watcher\Domain\HeadDecision;
use Intakt\Core\Watcher\Domain\StartedRound;
use Intakt\Plugin\Forge\Contract\ChangeLabels;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\OpenChange;
use LogicException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

// Review alone decides whether the verdicts of an earlier head still stand (T11), because it holds the round's recorded identity; the Watcher only records the answer.
final readonly class StaleVerdicts
{
    public function __construct(
        #[Autowire(service: 'query.bus')]
        private MessageBusInterface $queries,
        private MessageBusInterface $commands,
        private StartedRounds $rounds,
        private WatcherLedger $ledger,
        private ?ChangeLabels $labels = null,
    ) {
    }

    public function noteMovedHead(WatchedProject $project, OpenChange $open): void
    {
        foreach ($this->rounds->openRoundsBefore($project->name, $open) as $round) {
            $this->check($project, $open, $round);
        }
    }

    // Only the round on the refused head itself, because no identity tells Review that a head stopped booting; the rounds of earlier heads booted, so the moved-head check judges them. It reads no repository, because it runs before that check and must owe the withdrawal even when that check then fails.
    public function noteRefusedHead(WatchedProject $project, HeadDecision $decision): void
    {
        $round = $decision->failedBoot instanceof FailedBoot ? $this->rounds->roundAt($project->name, $decision->change) : null;

        if (!$round instanceof StartedRound) {
            return;
        }

        if (!$round->withdrawalWasRequested()) {
            $this->rounds->oweWithdrawalOnce($round, $this->ledger->verdictsStale(...));
        }

        $this->withdrawOwed($project, $decision->change);
    }

    // Sent on every settling of the pull request until Review takes it, whatever the head's next boot says, because only Review withdraws an approval already on the forge; a refusal throws, so the head stays due.
    public function withdrawOwed(WatchedProject $project, OpenChange $change): void
    {
        foreach ($this->rounds->owingWithdrawal($project->name, $change) as $round) {
            $this->withdraw($round);
            $this->rounds->withdrawn($round);
        }
    }

    private function withdraw(StartedRound $round): void
    {
        try {
            $this->commands->dispatch(new MarkVerdictsStale($round->roundId()));
        } catch (Throwable $refused) {
            // Boundary: rethrown with Review's own reason, because the head_failed card shows only the cause and the bus's wrapper does not say it was the withdrawal that failed.
            throw VerdictsWithdrawalRefused::because($refused);
        }
    }

    private function check(WatchedProject $project, OpenChange $open, StartedRound $round): void
    {
        $current = $this->ask(new IdentityAtHead($project->startAt($open, $this->labels?->steeringOf($project->name, $open) ?? new Labels()), new CommitId($round->head())), HeadIdentity::class);
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
