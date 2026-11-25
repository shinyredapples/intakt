<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
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

    // Recreating the schema per test costs a synced transaction per DDL statement on SQLite; the other engines keep the rebuild.
    public static function rowsIn(ContainerInterface $container): void
    {
        $manager = $container->get('doctrine')->getManager();
        assert($manager instanceof EntityManagerInterface);
        $manager->clear();
        $metadata = $manager->getMetadataFactory()->getAllMetadata();
        $connection = $container->get('doctrine.dbal.default_connection');
        assert($connection instanceof Connection);
        $tool = new SchemaTool($manager);

        if (!$connection->getDatabasePlatform() instanceof SQLitePlatform || [] !== $tool->getUpdateSchemaSql($metadata)) {
            self::in($container);
            $tool->createSchema($metadata);

            return;
        }

        // A migration test leaves the append-only triggers of the ledger behind; a schema the tool creates has none, so they go to make this reset equal to one.
        foreach (self::names($connection, 'trigger') as $trigger) {
            $connection->executeStatement(sprintf('DROP TRIGGER "%s"', $trigger));
        }

        self::deleteRows($connection, array_map(static fn ($class): string => $class->getTableName(), $metadata));
        $connection->executeStatement('DROP TABLE IF EXISTS reviewer_conversation');
        $connection->executeStatement('DROP TABLE IF EXISTS messenger_messages');
    }

    /**
     * @param list<string> $tables
     */
    private static function deleteRows(Connection $connection, array $tables): void
    {
        $enforced = (int) $connection->fetchOne('PRAGMA foreign_keys');
        $connection->executeStatement('PRAGMA foreign_keys = OFF');

        try {
            foreach ($tables as $table) {
                $connection->executeStatement(sprintf('DELETE FROM "%s"', $table));
            }
            if (in_array('sqlite_sequence', self::names($connection, 'table'), true)) {
                $connection->executeStatement('DELETE FROM sqlite_sequence');
            }
        } finally {
            $connection->executeStatement(sprintf('PRAGMA foreign_keys = %d', $enforced));
        }
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
