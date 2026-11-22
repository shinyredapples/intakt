<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20261028120000;
use DoctrineMigrations\Version20261122110000;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

// Covers nothing because the migrations bundle loads migrations by path, so their classes do not exist when PHPUnit reads the attributes.
#[Group('persistence')]
#[CoversNothing]
final class LedgerSubjectMigrationTest extends KernelTestCase
{
    private const array TRIGGERS = ['ledger_event_no_delete', 'ledger_event_no_replace', 'ledger_event_no_update'];

    public function testTheSubjectIsAddedWithoutAValueForStoredRowsAndTheAppendOnlyTriggersStay(): void
    {
        $connection = $this->ledgerOnSqlite();
        $connection->executeStatement("INSERT INTO ledger_event (id, kind, payload, recorded_at) VALUES ('0190f000-0000-7000-8000-000000000001', 'reviewer_started', '{}', '2026-11-22 12:00:00')");

        $this->up(new Version20261122110000($connection, new NullLogger()), $connection);

        self::assertSame([null], $connection->fetchFirstColumn('SELECT subject FROM ledger_event'));
        self::assertSame(self::TRIGGERS, $this->triggersOf($connection));
        self::assertContains('ledger_event_subject', $this->indexesOf($connection));
    }

    public function testTheWayBackDropsTheSubjectAndItsIndexAndKeepsTheAppendOnlyTriggers(): void
    {
        $connection = $this->ledgerOnSqlite();
        $this->up(new Version20261122110000($connection, new NullLogger()), $connection);

        $this->down(new Version20261122110000($connection, new NullLogger()), $connection);

        self::assertNotContains('subject', array_keys($connection->createSchemaManager()->introspectTableColumnsByUnquotedName('ledger_event')));
        self::assertNotContains('ledger_event_subject', $this->indexesOf($connection));
        self::assertSame(self::TRIGGERS, $this->triggersOf($connection));
    }

    public function testTheWayBackOnMysqlNamesTheTableOfTheIndexItDrops(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0']);
        $migration = new Version20261122110000($connection, new NullLogger());

        $migration->down(new Schema());

        self::assertSame('DROP INDEX ledger_event_subject ON ledger_event', $migration->getSql()[0]->getStatement());
    }

    private function ledgerOnSqlite(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE ledger_event (position INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, id CHAR(36) NOT NULL, kind VARCHAR(32) NOT NULL, payload CLOB NOT NULL, recorded_at DATETIME NOT NULL)');
        $this->up(new Version20261028120000($connection, new NullLogger()), $connection);

        return $connection;
    }

    /**
     * @return list<mixed>
     */
    private function triggersOf(Connection $connection): array
    {
        return $connection->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'trigger' AND tbl_name = 'ledger_event' ORDER BY name");
    }

    /**
     * @return list<mixed>
     */
    private function indexesOf(Connection $connection): array
    {
        return $connection->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'ledger_event'");
    }

    private function up(AbstractMigration $migration, Connection $connection): void
    {
        $migration->up(new Schema());
        $this->execute($migration, $connection);
    }

    private function down(AbstractMigration $migration, Connection $connection): void
    {
        $migration->down(new Schema());
        $this->execute($migration, $connection);
    }

    private function execute(AbstractMigration $migration, Connection $connection): void
    {
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
    }
}
