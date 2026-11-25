<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class EmptyDatabase
{
    // The mapped tables go through the schema tool; the conversation and queue tables are outside the mapping (the schema filter hides them), so they are dropped by name.
    public static function in(ContainerInterface $container): void
    {
        $registry = $container->get('doctrine');
        $manager = $registry->getManager();
        assert($manager instanceof EntityManagerInterface);
        new SchemaTool($manager)->dropDatabase();

        $connection = $container->get('doctrine.dbal.default_connection');
        $connection->executeStatement('DROP TABLE IF EXISTS reviewer_conversation');
        $connection->executeStatement('DROP TABLE IF EXISTS messenger_messages');
    }

    // The mapped tables are built once per database file and kept: a later call deletes their rows instead of dropping and creating them (the conversation and queue tables are still dropped by name, because tests create them from their migration), with no wrapping transaction so a test of a rollback still sees a real one.
    public static function rowsIn(ContainerInterface $container): void
    {
        $manager = $container->get('doctrine')->getManager();
        assert($manager instanceof EntityManagerInterface);
        $manager->clear();
        $metadata = $manager->getMetadataFactory()->getAllMetadata();
        $connection = $container->get('doctrine.dbal.default_connection');

        $present = self::names($connection, 'table');
        $mapped = array_map(static fn ($class): string => $class->getTableName(), $metadata);
        if ([] !== array_diff($mapped, $present)) {
            self::in($container);
            new SchemaTool($manager)->createSchema($metadata);

            return;
        }

        // A migration test leaves the append-only triggers of the ledger behind; a schema the tool creates has none, so they go to make this reset equal to one.
        foreach (self::names($connection, 'trigger') as $trigger) {
            $connection->executeStatement(sprintf('DROP TRIGGER "%s"', $trigger));
        }

        $connection->executeStatement('PRAGMA foreign_keys = OFF');
        foreach (array_intersect($present, $mapped) as $table) {
            $connection->executeStatement(sprintf('DELETE FROM "%s"', $table));
        }
        $connection->executeStatement('DELETE FROM sqlite_sequence');
        $connection->executeStatement('DROP TABLE IF EXISTS reviewer_conversation');
        $connection->executeStatement('DROP TABLE IF EXISTS messenger_messages');
        $connection->executeStatement('PRAGMA foreign_keys = ON');
    }

    /**
     * @return list<string>
     */
    private static function names(Connection $connection, string $type): array
    {
        /** @var list<string> $names */
        $names = $connection->fetchFirstColumn('SELECT name FROM sqlite_master WHERE type = ?', [$type]);

        return $names;
    }
}
