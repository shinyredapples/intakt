<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\UseCase\RemoveReplayedEvents;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Intakt\Core\Ledger\Contract\RemoveReplayedEvents;
use Intakt\Core\Platform\Contract\AsCommandHandler;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

// Through the connection, not the unit of work, because RefuseChangesToStoredEvents refuses every ORM delete of an event and stays as it is for every other path.
#[AsCommandHandler]
final readonly class RemoveReplayedEventsHandler
{
    private const string DELETE_REFUSAL = 'ledger_event_no_delete';

    public function __construct(private Connection $connection)
    {
    }

    // One transaction, because SQLite rolls the dropped trigger back with a failed delete and lets no other writer in while it holds it, so the refusal is never missing for anyone else.
    public function __invoke(RemoveReplayedEvents $command): void
    {
        $this->connection->transactional(function (Connection $connection) use ($command): void {
            $refusal = $this->liftDeleteRefusal($connection);
            $this->delete($connection, $command->events);
            $this->restore($connection, $refusal);
        });
    }

    // Read from the schema, not written again here, so the restored trigger is the one the migration created; null where there is none.
    private function liftDeleteRefusal(Connection $connection): ?string
    {
        if (!$connection->getDatabasePlatform() instanceof SQLitePlatform) {
            return null;
        }

        $trigger = $connection->fetchOne("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = ?", [self::DELETE_REFUSAL]);

        if (!is_string($trigger)) {
            return null;
        }

        $connection->executeStatement('DROP TRIGGER '.self::DELETE_REFUSAL);

        return $trigger;
    }

    /**
     * @param list<Uuid> $events
     */
    private function delete(Connection $connection, array $events): void
    {
        foreach ($events as $event) {
            $connection->delete('ledger_event', ['id' => $event], ['id' => UuidType::NAME]);
        }
    }

    private function restore(Connection $connection, ?string $refusal): void
    {
        if (null === $refusal) {
            return;
        }

        $connection->executeStatement($refusal);
    }
}
