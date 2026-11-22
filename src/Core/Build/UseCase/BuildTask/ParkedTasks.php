<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Build\Contract\BuildStep;
use Intakt\Core\Build\Contract\BuildStepOutcome;
use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\Domain\ParkedTaskRecord;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use PDO;
use PDOException;
use Symfony\Component\Clock\ClockInterface;

// The row and the asked step share one transaction, because a task shown as asked must have its question stored, and a stored question must have its step.
final readonly class ParkedTasks
{
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
    public function park(string $project, int $task, string $run, BuildQuestion $question): void
    {
        // Refused before any write, because inside a caller's transaction the commit only releases a savepoint and the caller could still take the question back.
        if ($this->connection->isTransactionActive()) {
            throw TaskNotParked::for($project, $task);
        }

        $record = new ParkedTaskRecord($project, $task, $question, $this->clock->now()->setTimezone(new DateTimeZone('UTC')));

        if ($this->storedWithItsAskedStep($record, $project, $task, $run)) {
            return;
        }

        $this->rollBackWhatTheRefusedCommitLeftOpen();
        // Reset after every failed parking, because the manager still holds the asked record the rollback took back, and a worker keeps the manager.
        $this->doctrine->resetManager();

        throw TaskNotParked::for($project, $task);
    }

    private function storedWithItsAskedStep(ParkedTaskRecord $record, string $project, int $task, string $run): bool
    {
        try {
            $this->connection->beginTransaction();
            // Replaced, because a task asked again before anyone cleared its row keeps one row with the newer question.
            $this->connection->delete(ParkedTaskRecord::TABLE, $record->keyRow());
            $this->connection->insert(ParkedTaskRecord::TABLE, $record->row(), ParkedTaskRecord::rowTypes());

            if (!$this->ledger->append(new LedgerEvent(LedgerEventKind::BuildStepEnded, $this->askedPayload($project, $task, $run)))->succeeded()) {
                return false;
            }

            $this->connection->commit();
        } catch (Exception) {
            return false;
        }

        return true;
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

    // Because the question is untrusted text, the payload names the task by its number only, as every other step's does.
    private function askedPayload(string $project, int $task, string $run): string
    {
        return json_encode([
            'project' => $project,
            'task' => (string) $task,
            'run' => $run,
            'step' => BuildStep::BuildJob->value,
            'outcome' => BuildStepOutcome::Asked->value,
            'failure' => null,
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
