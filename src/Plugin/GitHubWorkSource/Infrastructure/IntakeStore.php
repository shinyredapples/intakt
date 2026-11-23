<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Infrastructure;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeValidator;
use Intakt\Plugin\GitHubWorkSource\Domain\ListingRows;
use Intakt\Plugin\GitHubWorkSource\Domain\ProjectHeldByAnotherWriter;
use Intakt\Plugin\GitHubWorkSource\Domain\StoredIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\SyncState;
use Intakt\Plugin\GitHubWorkSource\Domain\TaskFailure;
use Intakt\Plugin\WorkSource\Contract\TaskNotQueued;
use InvalidArgumentException;
use LogicException;
use NoDiscard;
use PDO;
use SQLite3;
use SQLite3Exception;
use Throwable;

// Every call works in an entity manager of its own on the shared connection: a worker keeps the shared one between ticks, what another worker stored since is the truth, a write must flush only its own rows and never another caller's pending work, and a write that fails leaves nothing behind for a later flush.
final readonly class IntakeStore
{
    public function __construct(
        private EntityManagerInterface $entities,
    ) {
    }

    /**
     * @return list<StoredIssue>
     */
    public function issuesOf(string $project): array
    {
        return $this->readOf($project, fn (): array => array_values($this->storedIssuesOf($this->ownManager(), $project)));
    }

    public function syncStateOf(string $project): SyncState
    {
        return $this->readOf($project, fn (): SyncState => $this->ownManager()->find(IntakeValidator::class, $project)?->state() ?? SyncState::neverSynced());
    }

    // Written only while the stored generation is still the one read before the listing, because otherwise another worker stored a newer listing meanwhile and these rows would replace it with an older one.
    #[NoDiscard]
    public function recordIfStill(string $project, SyncState $readBefore, ListingRows $rows): bool
    {
        $listed = $this->listed($project, $rows->issues);

        try {
            return $this->inTransaction($this->ownManager(), function (EntityManagerInterface $manager) use ($project, $readBefore, $listed, $rows): bool {
                $sync = $manager->find(IntakeValidator::class, $project, LockMode::PESSIMISTIC_WRITE);
                if (($sync?->state() ?? SyncState::neverSynced())->generation !== $readBefore->generation) {
                    return false;
                }

                $this->replaceRows($manager, $project, $listed);
                // Kept also when the forge sent no validator, so the next listing is a full one and still has a generation to compare.
                if ($sync instanceof IntakeValidator) {
                    $sync->advance($rows->validator);

                    return true;
                }

                $manager->persist(new IntakeValidator($project, $rows->validator));

                return true;
            });
        } catch (UniqueConstraintViolationException|RetryableException $failure) {
            $this->rollBackARefusedCommit();

            return $this->lostAfter($failure, $project, $readBefore);
        }
    }

    // Apart from the issue rows, so a task that is closed after it failed for good still holds the tasks that wait on it. Under the lock the listing's write takes, so a failure and a listing write never interleave and a pick that reads the failures under that lock sees every failure recorded before it. A stored pull request is refused like a missing row, because the listing stores a pull request only for the rules to read and never queues one.
    public function failForGood(string $project, int $number, string $reason): void
    {
        $this->underTheListingLock($project, function (EntityManagerInterface $manager) use ($project, $number, $reason): void {
            $task = $manager->find(StoredIssue::class, ['project' => $project, 'number' => $number]);
            if (!$task instanceof StoredIssue || $task->isPullRequest()) {
                throw TaskNotQueued::named($project, $number);
            }

            $failure = $manager->find(TaskFailure::class, ['project' => $project, 'number' => $number]);
            if ($failure instanceof TaskFailure) {
                $failure->replace($reason);

                return;
            }

            $manager->persist(new TaskFailure($project, $number, $reason));
        });
    }

    // The validator goes too, because with it the forge answers the next listing as unchanged and the row would never be read again; the generation moves, so a listing read before this loses its write instead of storing the forgotten vouching again.
    public function forgetVouchingOf(string $project, int $number): void
    {
        $this->readOf($project, fn (): mixed => $this->underTheListingLock($project, static function (EntityManagerInterface $manager) use ($project, $number): null {
            $manager->find(StoredIssue::class, ['project' => $project, 'number' => $number])?->forgetVouching();
            $manager->find(IntakeValidator::class, $project)?->advance(null);

            return null;
        }));
    }

    /**
     * @return array<int, string>
     */
    public function failuresOf(string $project): array
    {
        return $this->readOf($project, fn (): array => $this->reasonsOf($this->ownManager(), $project));
    }

    // Read once the lock is held, because a failure recorded since the plan was made must keep its task from being picked: the pick is ordered before any failure that commits after this read.
    /**
     * @return array<int, string>
     */
    public function failuresUnderTheListingLock(string $project): array
    {
        return $this->readOf($project, fn (): array => $this->underTheListingLock($project, fn (EntityManagerInterface $manager): array => $this->reasonsOf($manager, $project)));
    }

    // A reader on SQLite's default journal lets the lock's write through and refuses only the COMMIT, so the transaction SQLite then keeps open is rolled back before the refusal is thrown: left open, every later transaction on the connection would be refused as already active, and an uncommitted failure would be read back as recorded.
    /**
     * @template T
     *
     * @param callable(EntityManagerInterface): T $work
     *
     * @return T
     */
    private function underTheListingLock(string $project, callable $work): mixed
    {
        try {
            return $this->inTransaction($this->ownManager(), function (EntityManagerInterface $manager) use ($project, $work): mixed {
                $this->lockTheSyncRow($manager, $project);

                return $work($manager);
            });
        } catch (RetryableException $refusal) {
            $this->rollBackARefusedCommit();

            throw $refusal;
        }
    }

    // SQLite has no row lock, and in WAL mode a transaction reads from the snapshot of its first read, so a failure could commit between the row read and the failure read and be missed. A write that changes no row, sent before any read, takes SQLite's write lock and reads the latest commit; a server database changes nothing for it and relies on the row lock.
    private function lockTheSyncRow(EntityManagerInterface $manager, string $project): void
    {
        $sync = $manager->getClassMetadata(IntakeValidator::class);
        $column = $sync->getColumnName('project');
        $manager->getConnection()->createQueryBuilder()
            ->update($sync->getTableName())
            ->set($column, $column)
            ->where('1 = 0')
            ->executeStatement();
        $manager->find(IntakeValidator::class, $project, LockMode::PESSIMISTIC_WRITE);
    }

    /**
     * @return array<int, string>
     */
    private function reasonsOf(EntityManagerInterface $manager, string $project): array
    {
        $failures = [];
        foreach ($this->rowsOf($manager, TaskFailure::class, $project) as $failure) {
            $failures[$failure->number()] = $failure->reason();
        }

        return $failures;
    }

    /**
     * @param list<StoredIssue> $issues
     *
     * @return array<int, StoredIssue>
     */
    private function listed(string $project, array $issues): array
    {
        $listed = [];
        foreach ($issues as $issue) {
            if ($issue->project() !== $project) {
                throw new InvalidArgumentException(sprintf('Issue #%d belongs to project "%s", not to "%s".', $issue->number(), $issue->project(), $project));
            }

            $listed[$issue->number()] = $issue;
        }

        return $listed;
    }

    // The rows become those of one complete listing, so an item it no longer shows was closed and its row goes; failures stay, because a closed task that failed for good still holds the tasks that wait on it.
    /**
     * @param array<int, StoredIssue> $listed
     */
    private function replaceRows(EntityManagerInterface $manager, string $project, array $listed): void
    {
        $stored = $this->storedIssuesOf($manager, $project);
        foreach ($listed as $number => $issue) {
            $this->keep($manager, $stored[$number] ?? null, $issue);
        }

        foreach (array_diff_key($stored, $listed) as $closed) {
            $manager->remove($closed);
        }
    }

    // Not the manager's own wrapInTransaction(): a server that aborts a deadlock victim's transaction itself refuses the rollback that follows, and that refusal would replace the deadlock, so the write would escape as a failure instead of being named as held, and a refused nested rollback would leave its level open on the shared connection.
    /**
     * @template T
     *
     * @param callable(EntityManagerInterface): T $write
     *
     * @return T
     */
    private function inTransaction(EntityManagerInterface $manager, callable $write): mixed
    {
        $connection = $manager->getConnection();
        $outer = $connection->getTransactionNestingLevel();
        $connection->beginTransaction();
        try {
            $written = $write($manager);
            $manager->flush();
            $connection->commit();

            return $written;
        } catch (Throwable $failure) {
            $manager->close();
            $this->rollBackAfter($connection, $outer);

            throw $this->refusalBehind($failure);
        }
    }

    // Back to the level the write started at, because the flush leaves its nested level counted when its own rollback was refused. A refused rollback is ignored, so the failure that ended the write is still thrown.
    private function rollBackAfter(Connection $connection, int $outer): void
    {
        for ($level = $connection->getTransactionNestingLevel(); $level > $outer; --$level) {
            try {
                $connection->rollBack();
            } catch (Exception) {
                $this->leaveALostSavepoint($connection, $level);
            }
        }
    }

    // Looked for in the previous exceptions too, because the flush runs its statements in a nested transaction of its own and rolls it back in a finally block: when the server already aborted the whole transaction, that rollback is refused and thrown instead, with the statement's refusal at the end of its chain.
    private function refusalBehind(Throwable $failure): Throwable
    {
        for ($cause = $failure; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
            if ($cause instanceof RetryableException) {
                return $cause;
            }
        }

        return $failure;
    }

    // DBAL counts the outermost transaction as ended before it asks the driver, but a nested one only after its savepoint rolled back. A commit of the nested level counts it as ended also when the release of its savepoint is refused, and it keeps nothing, because the level above is rolled back next.
    private function leaveALostSavepoint(Connection $connection, int $level): void
    {
        if ($connection->getTransactionNestingLevel() !== $level) {
            return;
        }

        try {
            $connection->commit();
        } catch (Exception) {
        }
    }

    // SQLite keeps a transaction open when it refuses its COMMIT as busy, on DBAL's PDO and native SQLite3 drivers alike, while DBAL already counts it as ended and never rolls it back: left open, its uncommitted generation would be read back as another worker's and hold the write lock for every later call on the connection.
    private function rollBackARefusedCommit(): void
    {
        $connection = $this->entities->getConnection();
        if ($connection->isTransactionActive()) {
            return;
        }

        $native = $connection->getNativeConnection();
        if ($native instanceof PDO && $native->inTransaction()) {
            $native->rollBack();
        }

        if ($native instanceof SQLite3) {
            $this->rollBackNative($native);
        }
    }

    // SQLite3 cannot say whether a transaction is open, so the rollback is tried and only its "no transaction is active" refusal is expected.
    private function rollBackNative(SQLite3 $native): void
    {
        try {
            $native->exec('ROLLBACK');
        } catch (SQLite3Exception $refusal) {
            if (!str_contains($refusal->getMessage(), 'no transaction is active')) {
                throw $refusal;
            }
        }
    }

    private function lostAfter(UniqueConstraintViolationException|RetryableException $failure, string $project, SyncState $readBefore): false
    {
        $stored = $this->syncStateOf($project);

        // The first sync of a project finds no row to lock, so a worker that commits the row after it was read makes the insert collide (or, on SQLite in WAL mode, the stale read snapshot refuses the write). It loses only when the newer listing is really stored.
        if ($stored->generation !== $readBefore->generation) {
            return false;
        }

        // A lock wait or a deadlock with the generation unmoved means another writer holds the project and has not committed: it may still roll back, so this is not a lost write and gets a name of its own. Any other failure stays one.
        throw $failure instanceof RetryableException ? ProjectHeldByAnotherWriter::of($project, $failure) : $failure;
    }

    // A query and not a repository: the shared configuration's repository factory keeps every manager it built a repository for, with the rows that manager loaded, for the life of the worker.
    /**
     * @template T of StoredIssue|TaskFailure
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    private function rowsOf(EntityManagerInterface $manager, string $class, string $project): array
    {
        $rows = $manager->createQueryBuilder()
            ->select('row')
            ->from($class, 'row')
            ->where('row.project = :project')
            ->orderBy('row.number', 'ASC')
            ->setParameter('project', $project)
            ->getQuery()
            ->getResult();

        return array_values(array_filter(is_array($rows) ? $rows : [], static fn (mixed $row): bool => $row instanceof $class));
    }

    private function ownManager(): EntityManagerInterface
    {
        // Without the shared event manager the mapping's listeners would not run, so another kind is refused instead of replaced.
        $events = $this->entities->getEventManager();
        if (!$events instanceof EventManager) {
            throw new LogicException(sprintf('The intake store needs a %s, %s given.', EventManager::class, $events::class));
        }

        return new EntityManager($this->entities->getConnection(), $this->entities->getConfiguration(), $events);
    }

    /**
     * @return array<int, StoredIssue>
     */
    private function storedIssuesOf(EntityManagerInterface $manager, string $project): array
    {
        $issues = [];
        foreach ($this->rowsOf($manager, StoredIssue::class, $project) as $issue) {
            $issues[$issue->number()] = $issue;
        }

        return $issues;
    }

    // Named as held, because another worker's commit under way holds a pending lock that refuses even a read, and nothing says yet whether it stores a newer listing.
    /**
     * @template T
     *
     * @param callable(): T $read
     *
     * @return T
     */
    private function readOf(string $project, callable $read): mixed
    {
        try {
            return $read();
        } catch (RetryableException $refusal) {
            throw ProjectHeldByAnotherWriter::of($project, $refusal);
        }
    }

    private function keep(EntityManagerInterface $manager, ?StoredIssue $stored, StoredIssue $issue): void
    {
        if ($stored instanceof StoredIssue) {
            $stored->replaceWith($issue);

            return;
        }

        $manager->persist($issue);
    }
}
