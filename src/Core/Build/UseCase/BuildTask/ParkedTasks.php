<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\Domain\ParkedTaskRecord;
use PDO;
use Symfony\Component\Clock\ClockInterface;

final readonly class ParkedTasks
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param Closure(): void $recordParking
     *
     * @throws TaskNotParked
     */
    public function park(BuildTaskRequest $request, BuildQuestion $question, Closure $recordParking): void
    {
        // Refused before anything is written, because PostgreSQL stores no NUL in a text column and the record must read back as it was checked.
        if ($this->holdsNul($question)) {
            throw TaskNotParked::holdingNul($request);
        }

        $record = new ParkedTaskRecord($request->project, $request->issue, $question, $this->clock->now());
        $outermost = !$this->connection->isTransactionActive();

        try {
            // In a savepoint, because PostgreSQL refuses every further statement of a caller's transaction in which one failed; the ledger writes on this same connection, so an event it refuses takes the new row back and the earlier question stays.
            $this->connection->transactional(static function (Connection $inner) use ($record, $recordParking): void {
                self::replace($inner, $record);
                $recordParking();
            });
        } catch (Exception $failure) {
            if ($outermost) {
                $this->rollBackWhatTheRefusedCommitLeftOpen();
            }

            throw TaskNotParked::for($request, $failure);
        }
    }

    // SQLite keeps the transaction open when a reader refuses its COMMIT, while DBAL counts it as closed (upstream/dbal-failed-commit-leaves-transaction-open/), so the new row would stay visible on this connection and the ledger's next write would join it.
    private function rollBackWhatTheRefusedCommitLeftOpen(): void
    {
        $native = $this->connection->getNativeConnection();

        if ($native instanceof PDO && $native->inTransaction()) {
            $native->rollBack();
        }
    }

    private function holdsNul(BuildQuestion $question): bool
    {
        return array_any([$question->question, $question->recommendation, ...$question->choices], static fn (string $text): bool => str_contains($text, "\0"));
    }

    // The earlier question of the same task goes, because only the latest build's question waits for a decision.
    private static function replace(Connection $inner, ParkedTaskRecord $record): void
    {
        $row = $record->row();
        $inner->delete(ParkedTaskRecord::TABLE, ['project' => $row['project'], 'task' => $row['task']], ['task' => 'integer']);
        $inner->insert(ParkedTaskRecord::TABLE, $row, ParkedTaskRecord::rowTypes());
    }
}
