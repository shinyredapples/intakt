<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\Domain\ParkedTaskRecord;
use Symfony\Component\Clock\ClockInterface;

final readonly class ParkedTasks
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws TaskNotParked
     */
    public function park(BuildTaskRequest $request, BuildQuestion $question): void
    {
        $record = new ParkedTaskRecord($request->project, $request->issue, $question, $this->clock->now());

        try {
            // In a savepoint, because PostgreSQL refuses every further statement of a caller's transaction in which one failed.
            $this->connection->transactional(static fn (Connection $inner): int|string => self::replace($inner, $record));
        } catch (Exception $failure) {
            throw TaskNotParked::for($request, $failure);
        }
    }

    // The earlier question of the same task goes, because only the latest build's question waits for a decision.
    private static function replace(Connection $inner, ParkedTaskRecord $record): int|string
    {
        $row = $record->row();
        $inner->delete(ParkedTaskRecord::TABLE, ['project' => $row['project'], 'task' => $row['task']], ['task' => 'integer']);

        return $inner->insert(ParkedTaskRecord::TABLE, $row, ParkedTaskRecord::rowTypes());
    }
}
