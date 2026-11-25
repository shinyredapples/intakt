<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Domain\AccountCooldownRecord;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

#[Group('persistence')]
#[CoversNothing]
final class EmptyDatabaseTest extends KernelTestCase
{
    public function testAResetDatabaseKeepsItsTablesAndHoldsNoRowsOfThePreviousTest(): void
    {
        EmptyDatabase::rowsIn(self::getContainer());
        $this->writeCooldown();

        EmptyDatabase::rowsIn(self::getContainer());

        self::assertSame(0, $this->rows());
    }

    public function testAResetAfterATestThatWroteRowsGivesTheIdsFromOneAgain(): void
    {
        EmptyDatabase::rowsIn(self::getContainer());
        $this->writeCooldown();
        $this->writeCooldown();

        EmptyDatabase::rowsIn(self::getContainer());
        $this->writeCooldown();

        self::assertEquals(1, $this->connection()->fetchOne('SELECT MIN(position) FROM budget_account_cooldown'));
    }

    public function testAResetOnADatabaseWithoutTablesCreatesTheMappedSchema(): void
    {
        EmptyDatabase::in(self::getContainer());
        self::assertNotContains('budget_account_cooldown', $this->tables());

        EmptyDatabase::rowsIn(self::getContainer());

        self::assertContains('budget_account_cooldown', $this->tables());
    }

    public function testAResetDropsTheTriggersAMigrationLeftBehind(): void
    {
        EmptyDatabase::rowsIn(self::getContainer());
        $this->connection()->executeStatement('CREATE TRIGGER budget_account_cooldown_no_delete BEFORE DELETE ON budget_account_cooldown BEGIN SELECT RAISE(ABORT, \'kept\'); END');
        $this->writeCooldown();

        EmptyDatabase::rowsIn(self::getContainer());

        self::assertSame(0, $this->rows());
    }

    public function testAResetDropsTheConversationAndQueueTablesByName(): void
    {
        EmptyDatabase::rowsIn(self::getContainer());
        $this->connection()->executeStatement('CREATE TABLE reviewer_conversation (id INTEGER PRIMARY KEY)');
        $this->connection()->executeStatement('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY)');

        EmptyDatabase::rowsIn(self::getContainer());

        self::assertNotContains('reviewer_conversation', $this->tables());
        self::assertNotContains('messenger_messages', $this->tables());
    }

    private function writeCooldown(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $now = new DateTimeImmutable('2026-11-02T10:00:00+00:00');
        $entities->persist(new AccountCooldownRecord(new Account(Provider::Anthropic, 'main'), $now->modify('+1 hour'), $now));
        $entities->flush();
    }

    private function rows(): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM budget_account_cooldown');
        self::assertIsNumeric($count);

        return (int) $count;
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        /** @var list<string> $tables */
        $tables = $this->connection()->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'table'");

        return $tables;
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
