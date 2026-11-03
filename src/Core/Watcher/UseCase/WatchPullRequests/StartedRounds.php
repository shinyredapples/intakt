<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Review\Contract\DiscardQueuedRound;
use Intakt\Core\Review\Contract\QueuedRound;
use Intakt\Core\Watcher\Domain\StartedRound;
use Intakt\Plugin\Forge\Contract\OpenChange;
use NoDiscard;
use PDO;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

final readonly class StartedRounds
{
    public function __construct(
        private EntityManagerInterface $entities,
        private PendingHeads $pending,
        private MessageBusInterface $commands,
    ) {
    }

    // One transaction for the round, its record and the end of the wait, so a crash or a failed start leaves the head due and nothing half done; a round queued before the rollback is discarded even when the rollback fails, because its checkout lives outside the database.
    /**
     * @param Closure(): QueuedRound $start
     */
    public function startOnce(string $project, OpenChange $change, Closure $start): void
    {
        $connection = $this->entities->getConnection();
        $connection->beginTransaction();
        $queued = null;

        try {
            $queued = $this->startUnlessStarted($project, $change, $start);
            $this->ensureStillInTransaction($connection, $queued);
            $this->record($project, $change, $queued);
            $this->pending->forget($project, $change);
            $connection->commit();
        } catch (Throwable $failure) {
            $this->undo($connection);
            $this->discard($queued);

            throw $failure;
        }
    }

    /**
     * @return list<StartedRound>
     */
    public function notYetRecorded(string $project): array
    {
        $rounds = [];

        foreach ($this->entities->getRepository(StartedRound::class)->findBy(['project' => $project, 'queuedRecorded' => false]) as $loaded) {
            $round = $this->readAgain($loaded);

            if (true === $round?->owesQueuedLine()) {
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

        foreach ($this->entities->getRepository(StartedRound::class)->findBy(['project' => $project, 'change' => $change->id->value]) as $loaded) {
            $round = $this->readAgain($loaded);

            if (false === $round?->isStale() && $round->head() !== $change->head->hash) {
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

    /**
     * @param Closure(): QueuedRound $start
     */
    private function startUnlessStarted(string $project, OpenChange $change, Closure $start): ?QueuedRound
    {
        if ($this->isStarted($project, $change)) {
            return null;
        }

        return $start();
    }

    // A lost connection ends the transaction and DBAL reconnects in autocommit, so the record and the end of the wait would be written for real and the discarded round's head would never be due again.
    private function ensureStillInTransaction(Connection $connection, ?QueuedRound $queued): void
    {
        if ($queued instanceof QueuedRound && !$this->isInTransaction($connection)) {
            throw RoundTransactionLost::for($queued->roundId);
        }
    }

    // DBAL counts only the transactions it began and ended, so a commit past it (a native one, or MySQL's implicit commit on DDL) is seen only on the driver's own connection.
    private function isInTransaction(Connection $connection): bool
    {
        $native = $connection->getNativeConnection();

        return $connection->isTransactionActive() && (!$native instanceof PDO || $native->inTransaction());
    }

    private function record(string $project, OpenChange $change, ?QueuedRound $queued): void
    {
        if (!$queued instanceof QueuedRound) {
            return;
        }

        $this->entities->persist(new StartedRound($project, $change, $queued->roundId));
        $this->entities->flush();
    }

    private function isStarted(string $project, OpenChange $change): bool
    {
        return 0 < $this->entities->getRepository(StartedRound::class)->count(['project' => $project, 'change' => $change->id->value, 'head' => $change->head->hash]);
    }

    // A rollback that fails (the server already ended the transaction) must not hide the original failure or skip the discard.
    private function undo(Connection $connection): void
    {
        try {
            $connection->rollBack();
        } catch (Exception) {
        }
    }

    private function discard(?QueuedRound $queued): void
    {
        if ($queued instanceof QueuedRound) {
            $this->commands->dispatch(new DiscardQueuedRound($queued));
        }
    }

    // Read again by its key rather than refreshed, because a worker keeps its entity manager, another worker may have changed or removed the row, and a refresh of a deleted row keeps its old values.
    private function readAgain(StartedRound $loaded): ?StartedRound
    {
        $this->entities->detach($loaded);

        return $this->entities->find(StartedRound::class, ['project' => $loaded->project(), 'change' => $loaded->change(), 'head' => $loaded->head()]);
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
