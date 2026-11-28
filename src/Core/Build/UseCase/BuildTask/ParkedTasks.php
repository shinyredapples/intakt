<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Build\Contract\BuildStep;
use Intakt\Core\Build\Contract\BuildStepOutcome;
use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\Domain\BuildStopCause;
use Intakt\Core\Build\Domain\ParkedTaskRecord;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\Mentions;
use Intakt\Core\Ledger\Contract\PayloadFields;
use PDO;
use PDOException;
use Symfony\Component\Clock\ClockInterface;

// The row, the asked step and the task_parked event share one transaction, because a task shown as asked must have its question stored, and a stored question must have its step.
final readonly class ParkedTasks
{
    // A few, because each conflict means another run of the same task wrote in between, and more runs than this do not meet in one parking.
    private const int ATTEMPTS = 3;

    public function __construct(
        private Connection $connection,
        private Ledger $ledger,
        private ManagerRegistry $doctrine,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws TaskNotParked
     */
    public function park(BuildRun $run, BuildQuestion $question, ?BuildStopCause $stopCause): void
    {
        // Refused before any write, because inside a caller's transaction the commit only releases a savepoint and the caller could still take the question back.
        if ($this->connection->isTransactionActive()) {
            throw TaskNotParked::for($run->project, $run->task);
        }

        $record = new ParkedTaskRecord($run->project, $run->task, $question, $this->clock->now()->setTimezone(new DateTimeZone('UTC')), $run->id);

        if ($this->storedWithItsAskedStep($record, $run, $stopCause)) {
            return;
        }

        $this->rollBackWhatTheRefusedCommitLeftOpen();
        // Reset after every failed parking, because the manager still holds the asked record the rollback took back, and a worker keeps the manager.
        $this->doctrine->resetManager();

        throw TaskNotParked::for($run->project, $run->task);
    }

    // A task that is not parked has no row, so clearing it is no failure; a question of a run that started later stays, because the older run's build does not answer it.
    /**
     * @throws ParkedTaskNotCleared
     */
    public function clear(BuildRun $run): void
    {
        try {
            $stored = $this->storedRowOf($run);

            if (null !== $stored && !$this->startedLater($stored['run'], $run)) {
                $this->deleteSeen($stored);
            }
        } catch (Exception) {
            throw ParkedTaskNotCleared::for($run->project, $run->task);
        }
    }

    private function storedWithItsAskedStep(ParkedTaskRecord $record, BuildRun $run, ?BuildStopCause $stopCause): bool
    {
        try {
            for ($attempt = 1; $attempt <= self::ATTEMPTS; ++$attempt) {
                if ($this->startedWithTheRow($record, $run)) {
                    return $this->endedWithTheEvents($run, $stopCause);
                }
            }
        } catch (Exception) {
            return false;
        }

        return false;
    }

    // False when another run wrote the row after it was read: the order is then decided again against that run's row, because the question of the run that started later must win.
    /**
     * @throws Exception
     */
    private function startedWithTheRow(ParkedTaskRecord $record, BuildRun $run): bool
    {
        // Read before the transaction, because SQLite refuses a transaction that read first and then writes while another one writes.
        $stored = $this->storedRowOf($run);
        $keepStored = null !== $stored && $this->startedLater($stored['run'], $run);

        $this->connection->beginTransaction();

        if ($keepStored) {
            return true;
        }

        try {
            $this->replace($stored, $record);
        } catch (UniqueConstraintViolationException) {
            $this->connection->rollBack();

            return false;
        }

        return true;
    }

    /**
     * @throws Exception
     */
    private function endedWithTheEvents(BuildRun $run, ?BuildStopCause $stopCause): bool
    {
        if (!$this->appended(new LedgerEvent(LedgerEventKind::BuildStepEnded, $run->stepPayload(new EndedStep(BuildStep::BuildJob, BuildStepOutcome::Asked, null, $stopCause))))) {
            return false;
        }

        if (!$this->appended(new LedgerEvent(LedgerEventKind::TaskParked, $run->parkedPayload()))) {
            return false;
        }

        $this->connection->commit();

        return true;
    }

    /**
     * @return array{project: string, task: int, run: ?string}|null
     */
    private function storedRowOf(BuildRun $run): ?array
    {
        $row = $this->connection->fetchAssociative(
            sprintf('SELECT run FROM %s WHERE project = ? AND task = ?', ParkedTaskRecord::TABLE),
            [$run->project, $run->task],
            [ParameterType::STRING, ParameterType::INTEGER],
        );

        if (false === $row) {
            return null;
        }

        return ['project' => $run->project, 'task' => $run->task, 'run' => is_string($row['run']) ? $row['run'] : null];
    }

    // By the order of their first rows in the ledger, which the task step's start lock keeps, as the task page decides it; a run without a row there counts as earlier, because it would never be shown.
    private function startedLater(?string $storedRun, BuildRun $run): bool
    {
        if (null === $storedRun || $storedRun === $run->id) {
            return false;
        }

        $started = [];

        foreach ($this->ledger->readOfKindsMentioning(new Mentions($storedRun, $run->id), LedgerEventKind::BuildStepEnded) as $recorded) {
            $payload = PayloadFields::of($recorded);

            if ($run->project === $payload->text('project') && (string) $run->task === $payload->text('task')) {
                $started[$payload->text('run') ?? ''] = true;
            }
        }

        return [$run->id, $storedRun] === array_keys(array_intersect_key($started, [$run->id => true, $storedRun => true]));
    }

    // Only the row as it was read, because another run may have replaced it since; a parking then meets that row's primary key and decides again.
    /**
     * @param array{project: string, task: int, run: ?string} $stored
     */
    private function deleteSeen(array $stored): void
    {
        $this->connection->delete(ParkedTaskRecord::TABLE, $stored, ['task' => 'integer']);
    }

    // Replaced, because a task asked again before anyone cleared its row keeps one row with the newer question.
    /**
     * @param array{project: string, task: int, run: ?string}|null $stored
     */
    private function replace(?array $stored, ParkedTaskRecord $record): void
    {
        if (null !== $stored) {
            $this->deleteSeen($stored);
        }

        $this->connection->insert(ParkedTaskRecord::TABLE, $record->row(), ParkedTaskRecord::rowTypes());
    }

    private function appended(LedgerEvent $event): bool
    {
        return $this->ledger->append($event)->succeeded();
    }

    // Native, because DBAL counts a commit the database refused as ended while SQLite keeps the transaction open (upstream/dbal-failed-commit-leaves-transaction-open/).
    private function rollBackWhatTheRefusedCommitLeftOpen(): void
    {
        if (!$this->connection->isConnected()) {
            return;
        }

        try {
            $native = $this->connection->getNativeConnection();

            if ($native instanceof PDO && $native->inTransaction()) {
                $native->rollBack();
            }
        } catch (Exception|PDOException) {
            // Swallowed, because the caller must learn that the task is not parked, never that the cleanup after it failed too.
        }

        // Closed, because DBAL's count of open transactions can also outlive one the database rolled back itself, and only closing resets it; the connection opens again on its next use.
        $this->connection->close();
    }
}
