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
        $platform = $connection->getDatabasePlatform();
        $sqlite = $platform instanceof SQLitePlatform;
        $stored = $sqlite ? $connection->fetchOne('PRAGMA user_version') : null;
        $tool = new SchemaTool($manager);
        $mapping = $sqlite ? $tool->getCreateSchemaSql($metadata) : [];

        // Comparing the schema with the mapping costs about as much as rebuilding it, so the file header keeps a fingerprint of the mapping's create statements and of the definitions the tool wrote: a changed mapping and a test that altered the schema both force a rebuild.
        if (!is_numeric($stored) || self::fingerprint($mapping, $connection) !== (int) $stored) {
            self::in($container);
            $tool->createSchema($metadata);
            if ($sqlite) {
                $connection->executeStatement(sprintf('PRAGMA user_version = %d', self::fingerprint($mapping, $connection)));
            }

            return;
        }

        // A migration test leaves the append-only triggers of the ledger behind; a schema the tool creates has none, so they go to make this reset equal to one; temp-schema objects stay because they end with their connection.
        foreach (self::names($connection, 'trigger') as $trigger) {
            $connection->executeStatement(sprintf('DROP TRIGGER %s', $platform->quoteSingleIdentifier($trigger)));
        }

        self::deleteRows($connection, array_map(static fn ($class): string => $class->getTableName(), $metadata));
        $connection->executeStatement('DROP TABLE IF EXISTS reviewer_conversation');
        $connection->executeStatement('DROP TABLE IF EXISTS messenger_messages');
    }

    // user_version holds a signed 32-bit number and starts at 0, so the hash is cut to 31 bits and never 0.
    /**
     * @param list<string> $mapping
     */
    private static function fingerprint(array $mapping, Connection $connection): int
    {
        $rows = $connection->fetchAllNumeric(
            "SELECT type, name, sql FROM sqlite_master WHERE type IN ('table', 'index') AND tbl_name NOT IN ('sqlite_sequence', 'reviewer_conversation', 'messenger_messages') ORDER BY type, name",
        );

        return max(1, crc32(json_encode([$mapping, $rows], JSON_THROW_ON_ERROR)) & 0x7FFFFFFF);
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

    /**
     * @param list<string> $tables
     */
    private static function deleteRows(Connection $connection, array $tables): void
    {
        $enforced = in_array($connection->fetchOne('PRAGMA foreign_keys'), [1, '1'], true) ? 'ON' : 'OFF';
        $connection->executeStatement('PRAGMA foreign_keys = OFF');
        $platform = $connection->getDatabasePlatform();

        try {
            foreach ($tables as $table) {
                $connection->executeStatement(sprintf('DELETE FROM %s', $platform->quoteSingleIdentifier($table)));
            }
            if (in_array('sqlite_sequence', self::names($connection, 'table'), true)) {
                $connection->executeStatement('DELETE FROM sqlite_sequence');
            }
        } finally {
            $connection->executeStatement(sprintf('PRAGMA foreign_keys = %s', $enforced));
        }
    }
}
