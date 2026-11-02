<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Watcher\Domain\StartedRound;
use Intakt\Plugin\Forge\Contract\OpenChange;
use NoDiscard;

final readonly class StartedRounds
{
    public function __construct(
        private EntityManagerInterface $entities,
        private PendingHeads $pending,
    ) {
    }

    // One transaction for the round, its record and the end of the wait, so a crash or a failed start leaves the head due and nothing half done.
    /**
     * @param Closure(): string $start
     */
    public function startOnce(string $project, OpenChange $change, Closure $start): void
    {
        $this->entities->getConnection()->transactional(function () use ($project, $change, $start): void {
            if (!$this->isStarted($project, $change)) {
                $this->record($project, $change, $start());
            }

            $this->pending->forget($project, $change);
        });
    }

    /**
     * @return list<StartedRound>
     */
    public function notYetRecorded(string $project): array
    {
        $rounds = [];

        foreach ($this->entities->getRepository(StartedRound::class)->findBy(['project' => $project, 'queuedRecorded' => false]) as $round) {
            // Refreshed because a worker keeps its entity manager and another worker may have recorded the line.
            $this->entities->refresh($round);

            if ($round->owesQueuedLine()) {
                $rounds[] = $round;
            }
        }

        return $rounds;
    }

    // Claimed and recorded in one transaction with the ledger's own write, so a failed append leaves the line owed and a second worker that claims the same round finds it taken.
    /**
     * @param Closure(StartedRound): void $record
     */
    public function recordOnce(StartedRound $round, Closure $record): void
    {
        $this->entities->getConnection()->transactional(function () use ($round, $record): void {
            if ($this->claim($round)) {
                $record($round);
            }
        });
    }

    // Only rounds not yet found stale, because a round is reported stale once.
    /**
     * @return list<StartedRound>
     */
    public function openRoundsBefore(string $project, OpenChange $change): array
    {
        $rounds = [];

        foreach ($this->entities->getRepository(StartedRound::class)->findBy(['project' => $project, 'change' => $change->id->value]) as $round) {
            // Refreshed because a worker keeps its entity manager and another worker may have changed the row.
            $this->entities->refresh($round);

            if (!$round->isStale() && $round->head() !== $change->head->hash) {
                $rounds[] = $round;
            }
        }

        return $rounds;
    }

    // Marked and recorded in one transaction with the ledger's own write, so a failed append leaves the round unmarked for a later tick and a second worker that marks the same round finds it taken.
    /**
     * @param Closure(StartedRound): void $record
     */
    public function markStaleOnce(StartedRound $round, Closure $record): void
    {
        $this->entities->getConnection()->transactional(function () use ($round, $record): void {
            if ($this->markStale($round)) {
                $record($round);
            }
        });
    }

    private function isStarted(string $project, OpenChange $change): bool
    {
        return 0 < $this->entities->getRepository(StartedRound::class)->count(['project' => $project, 'change' => $change->id->value, 'head' => $change->head->hash]);
    }

    private function record(string $project, OpenChange $change, string $roundId): void
    {
        $this->entities->persist(new StartedRound($project, $change, $roundId));
        $this->entities->flush();
    }

    private function claim(StartedRound $round): bool
    {
        $claimed = $this->entities->createQueryBuilder()
            ->update(StartedRound::class, 'r')
            ->set('r.queuedRecorded', ':recorded')
            ->where('r.project = :project AND r.change = :change AND r.head = :head AND r.queuedRecorded = :owed')
            ->setParameter('recorded', true)
            ->setParameter('owed', false)
            ->setParameter('project', $round->project())
            ->setParameter('change', $round->change())
            ->setParameter('head', $round->head())
            ->getQuery()
            ->execute();

        return 1 === $claimed;
    }

    // One statement on the stored row, because the review's queries clear the entity manager and a loaded round would no longer be written; false when another worker marked it first.
    #[NoDiscard]
    private function markStale(StartedRound $round): bool
    {
        $marked = $this->entities->createQueryBuilder()
            ->update(StartedRound::class, 'r')
            ->set('r.stale', ':stale')
            ->where('r.project = :project AND r.change = :change AND r.head = :head AND r.stale = :fresh')
            ->setParameter('stale', true)
            ->setParameter('fresh', false)
            ->setParameter('project', $round->project())
            ->setParameter('change', $round->change())
            ->setParameter('head', $round->head())
            ->getQuery()
            ->execute();

        return 1 === $marked;
    }
}
