<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Review\Contract\QueuedRound;
use Intakt\Core\Watcher\Domain\StartedRound;
use Intakt\Plugin\Forge\Contract\OpenChange;
use NoDiscard;
use PDO;
use Throwable;

final readonly class StartedRounds
{
    public function __construct(
        private EntityManagerInterface $entities,
        private PendingHeads $pending,
        private DiscardedRounds $discarded,
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
            $stored = $this->roundAt($project, $change);
            $queued = $this->startUnlessStarted($stored, $start);
            $this->ensureStillInTransaction($connection, $queued);
            $this->record($project, $change, $queued);
            $this->endTheWait($project, $change, $stored);
            $connection->commit();
            // Boundary: rolls back, discards the kept snapshot and rethrows, because a round that was not recorded must leave nothing behind.
        } catch (Throwable $failure) {
            $this->undo($connection);
            $this->discarded->discard($queued);

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

    // At any head and stale or not, because the label mirrors the latest round Review stored for the pull request, and a moved head keeps that round until its own one starts.
    public function hasRoundOf(string $project, OpenChange $change): bool
    {
        return $this->entities->getRepository(StartedRound::class)->count(['project' => $project, 'change' => $change->id->value]) > 0;
    }

    // Read once per start, so the row this read returns decides both whether the round starts and whether the head stays due; a stale row is returned too, because a start replaces it with a fresh round.
    public function roundAt(string $project, OpenChange $change): ?StartedRound
    {
        $loaded = $this->entities->find(StartedRound::class, ['project' => $project, 'change' => $change->id->value, 'head' => $change->head->hash]);

        return $loaded instanceof StartedRound ? $this->readAgain($loaded) : null;
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

    // Marked, owed and recorded in one transaction with the ledger's own write, so a withdrawal is never marked without being owed; owed once per round, also when a move already found it stale, whose line is then not recorded again.
    /**
     * @param Closure(StartedRound): void $record
     */
    public function oweWithdrawalOnce(StartedRound $round, Closure $record): void
    {
        $this->entities->getConnection()->transactional(function () use ($round, $record): void {
            $marked = 1 === $this->update($round, ['stale' => true], ['stale' => false]);
            $this->update($round, ['withdrawalRequested' => true, 'withdrawalOwed' => true], ['withdrawalRequested' => false]);

            if ($marked) {
                $record($round);
            }
        });
    }

    // Every head of the pull request, because the head may have moved since Review refused the withdrawal.
    /**
     * @return list<StartedRound>
     */
    public function owingWithdrawal(string $project, OpenChange $change): array
    {
        $rounds = [];

        foreach ($this->entities->getRepository(StartedRound::class)->findBy(['project' => $project, 'change' => $change->id->value, 'withdrawalOwed' => true]) as $loaded) {
            $round = $this->readAgain($loaded);

            if (true === $round?->owesWithdrawal()) {
                $rounds[] = $round;
            }
        }

        return $rounds;
    }

    public function withdrawn(StartedRound $round): void
    {
        $this->update($round, ['withdrawalOwed' => false], []);
    }

    /**
     * @param Closure(): QueuedRound $start
     */
    private function startUnlessStarted(?StartedRound $stored, Closure $start): ?QueuedRound
    {
        if ($this->isStarted($stored)) {
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

        $this->replaceStaleRound($project, $change);
        $this->entities->persist(new StartedRound($project, $change, $queued->roundId, $queued->labels));
        $this->entities->flush();
    }

    // Decided from the same read as the start, because another tick records the owed line without the change lock: a second read could find the line taken and forget the head whose start was just suppressed. The head then stays due, so a later tick starts its fresh round.
    private function endTheWait(string $project, OpenChange $change, ?StartedRound $stored): void
    {
        if ($stored instanceof StartedRound && $this->awaitsReplacement($stored)) {
            return;
        }

        $this->pending->forget($project, $change);
    }

    // A stale round whose queued line is still owed keeps the head started, so the line is recorded before a returning head replaces the row.
    private function isStarted(?StartedRound $stored): bool
    {
        return $stored instanceof StartedRound && !$this->isReplaceable($stored);
    }

    private function awaitsReplacement(StartedRound $round): bool
    {
        return $round->isStale() && $round->owesQueuedLine();
    }

    private function isReplaceable(StartedRound $round): bool
    {
        return $round->isStale() && !$round->owesQueuedLine();
    }

    // The key holds one row per head, and the verdicts of a stale round no longer count, so a head that returns gets a fresh round in the place of the old row; inside the start transaction a failed start restores it.
    private function replaceStaleRound(string $project, OpenChange $change): void
    {
        $loaded = $this->entities->find(StartedRound::class, ['project' => $project, 'change' => $change->id->value, 'head' => $change->head->hash]);

        if ($loaded instanceof StartedRound) {
            $this->entities->detach($loaded);
        }

        $this->entities->createQueryBuilder()
            ->delete(StartedRound::class, 'r')
            ->where('r.project = :project AND r.change = :change AND r.head = :head AND r.stale = :stale')
            ->setParameter('project', $project)
            ->setParameter('change', $change->id->value)
            ->setParameter('head', $change->head->hash)
            ->setParameter('stale', true)
            ->getQuery()
            ->execute();
    }

    // A rollback that fails (the server already ended the transaction) must not hide the original failure or skip the discard.
    private function undo(Connection $connection): void
    {
        try {
            $connection->rollBack();
        } catch (Exception) {
        }
    }

    // Read again by its key rather than refreshed, because a worker keeps its entity manager, another worker may have changed or removed the row, and a refresh of a deleted row keeps its old values.
    private function readAgain(StartedRound $loaded): ?StartedRound
    {
        $this->entities->detach($loaded);

        return $this->entities->find(StartedRound::class, ['project' => $loaded->project(), 'change' => $loaded->change(), 'head' => $loaded->head()]);
    }

    // The round's identity is part of the condition, because a returning head replaces the row under the same key and a delayed worker holding the old round must not claim the fresh one.
    private function claim(StartedRound $round): bool
    {
        $claimed = $this->entities->createQueryBuilder()
            ->update(StartedRound::class, 'r')
            ->set('r.queuedRecorded', ':recorded')
            ->where('r.project = :project AND r.change = :change AND r.head = :head AND r.roundId = :round AND r.queuedRecorded = :owed')
            ->setParameter('recorded', true)
            ->setParameter('owed', false)
            ->setParameter('project', $round->project())
            ->setParameter('change', $round->change())
            ->setParameter('head', $round->head())
            ->setParameter('round', $round->roundId())
            ->getQuery()
            ->execute();

        return 1 === $claimed;
    }

    // One statement on the stored row, because the review's queries clear the entity manager and a loaded round would no longer be written; false when another worker marked it first or a returning head replaced it.
    #[NoDiscard]
    private function markStale(StartedRound $round): bool
    {
        $marked = $this->entities->createQueryBuilder()
            ->update(StartedRound::class, 'r')
            ->set('r.stale', ':stale')
            ->where('r.project = :project AND r.change = :change AND r.head = :head AND r.roundId = :round AND r.stale = :fresh')
            ->setParameter('stale', true)
            ->setParameter('fresh', false)
            ->setParameter('project', $round->project())
            ->setParameter('change', $round->change())
            ->setParameter('head', $round->head())
            ->setParameter('round', $round->roundId())
            ->getQuery()
            ->execute();

        return 1 === $marked;
    }

    // One statement that names the round's identity, because the review's queries clear the entity manager and a returning head replaces the row under the same key.
    /**
     * @param array<string, bool> $set
     * @param array<string, bool> $where
     */
    private function update(StartedRound $round, array $set, array $where): int
    {
        $update = $this->entities->createQueryBuilder()
            ->update(StartedRound::class, 'r')
            ->where('r.project = :project AND r.change = :change AND r.head = :head AND r.roundId = :round')
            ->setParameter('project', $round->project())
            ->setParameter('change', $round->change())
            ->setParameter('head', $round->head())
            ->setParameter('round', $round->roundId());

        foreach ($set as $field => $value) {
            $update->set('r.'.$field, ':set_'.$field)->setParameter('set_'.$field, $value);
        }

        foreach ($where as $field => $value) {
            $update->andWhere('r.'.$field.' = :was_'.$field)->setParameter('was_'.$field, $value);
        }

        $changed = $update->getQuery()->execute();

        return is_int($changed) ? $changed : 0;
    }
}
