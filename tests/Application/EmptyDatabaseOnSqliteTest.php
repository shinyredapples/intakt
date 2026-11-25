<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Domain\AccountCooldownRecord;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

// Not in the persistence group, because MySQL, MariaDB and PostgreSQL never take the SQLite path these cases prove.
#[CoversNothing]
final class EmptyDatabaseOnSqliteTest extends KernelTestCase
{
    public function testAResetOfAnUnchangedSchemaLeavesEveryTableInPlace(): void
    {
        EmptyDatabase::rowsIn(self::getContainer());
        $version = $this->connection()->fetchOne('PRAGMA schema_version');
        $this->writeCooldown();

        EmptyDatabase::rowsIn(self::getContainer());

        self::assertSame($version, $this->connection()->fetchOne('PRAGMA schema_version'));
    }

    public function testAResetKeepsForeignKeyEnforcementOffWhenItWasOff(): void
    {
        EmptyDatabase::rowsIn(self::getContainer());
        $this->connection()->executeStatement('PRAGMA foreign_keys = OFF');

        EmptyDatabase::rowsIn(self::getContainer());

        self::assertEquals(0, $this->connection()->fetchOne('PRAGMA foreign_keys'));
    }

    public function testAResetKeepsForeignKeyEnforcementOnWhenItWasOn(): void
    {
        EmptyDatabase::rowsIn(self::getContainer());
        $this->connection()->executeStatement('PRAGMA foreign_keys = ON');

        EmptyDatabase::rowsIn(self::getContainer());

        self::assertEquals(1, $this->connection()->fetchOne('PRAGMA foreign_keys'));
    }

    public function testAResetWhoseDeleteFailsStillRestoresForeignKeyEnforcement(): void
    {
        EmptyDatabase::rowsIn(self::getContainer());
        $connection = $this->connection();
        $connection->executeStatement('PRAGMA foreign_keys = ON');
        $connection->executeStatement('PRAGMA busy_timeout = 0');
        $writer = DriverManager::getConnection($connection->getParams());
        $writer->executeStatement('BEGIN IMMEDIATE');

        try {
            EmptyDatabase::rowsIn(self::getContainer());
            self::fail('The other connection holds the write lock.');
        } catch (Exception $error) {
            self::assertStringContainsString('database is locked', $error->getMessage());
        } finally {
            $writer->executeStatement('ROLLBACK');
            $writer->close();
        }

        self::assertEquals(1, $connection->fetchOne('PRAGMA foreign_keys'));
    }

    public function testAResetAfterAColumnWasDroppedRestoresTheMappedSchema(): void
    {
        EmptyDatabase::rowsIn(self::getContainer());
        $this->connection()->executeStatement('ALTER TABLE watcher_boot_result DROP COLUMN alert_pending');

        EmptyDatabase::rowsIn(self::getContainer());

        self::assertContains('alert_pending', $this->connection()->fetchFirstColumn("SELECT name FROM pragma_table_info('watcher_boot_result')"));
    }

    public function testAResetDropsTheTriggersAMigrationLeftBehind(): void
    {
        EmptyDatabase::rowsIn(self::getContainer());
        $this->connection()->executeStatement('CREATE TRIGGER budget_account_cooldown_no_delete BEFORE DELETE ON budget_account_cooldown BEGIN SELECT RAISE(ABORT, \'kept\'); END');
        $this->writeCooldown();

        EmptyDatabase::rowsIn(self::getContainer());

        self::assertEquals(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM budget_account_cooldown'));
    }

    public function testAFileWhoseFingerprintAnOlderMappingWroteIsRebuiltToTheCurrentMapping(): void
    {
        EmptyDatabase::rowsIn(self::getContainer());
        $connection = $this->connection();
        $connection->executeStatement('ALTER TABLE watcher_boot_result DROP COLUMN alert_pending');
        $definitions = $connection->fetchAllNumeric(
            "SELECT type, name, sql FROM sqlite_master WHERE type IN ('table', 'index') AND tbl_name NOT IN ('sqlite_sequence', 'reviewer_conversation', 'messenger_messages') ORDER BY type, name",
        );
        $connection->executeStatement(sprintf('PRAGMA user_version = %d', max(1, crc32(json_encode($definitions, JSON_THROW_ON_ERROR)) & 0x7FFFFFFF)));

        EmptyDatabase::rowsIn(self::getContainer());

        self::assertContains('alert_pending', $connection->fetchFirstColumn("SELECT name FROM pragma_table_info('watcher_boot_result')"));
    }

    public function testAResetDropsATriggerWithAQuoteInItsName(): void
    {
        EmptyDatabase::rowsIn(self::getContainer());
        $this->connection()->executeStatement('CREATE TRIGGER "keep""rows" BEFORE DELETE ON budget_account_cooldown BEGIN SELECT RAISE(ABORT, \'kept\'); END');

        EmptyDatabase::rowsIn(self::getContainer());

        self::assertEquals(0, $this->connection()->fetchOne("SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger'"));
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function writeCooldown(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $now = new DateTimeImmutable('2026-11-02T10:00:00+00:00');
        $entities->persist(new AccountCooldownRecord(new Account(Provider::Anthropic, 'main'), $now->modify('+1 hour'), $now));
        $entities->flush();
    }
}
