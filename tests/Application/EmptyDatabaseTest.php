<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
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
        self::assertFalse($this->exists('budget_account_cooldown'));

        EmptyDatabase::rowsIn(self::getContainer());

        self::assertTrue($this->exists('budget_account_cooldown'));
    }

    public function testAResetDropsTheConversationAndQueueTablesByName(): void
    {
        EmptyDatabase::rowsIn(self::getContainer());
        $this->connection()->executeStatement('CREATE TABLE reviewer_conversation (id INTEGER PRIMARY KEY)');
        $this->connection()->executeStatement('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY)');

        EmptyDatabase::rowsIn(self::getContainer());

        self::assertFalse($this->exists('reviewer_conversation'));
        self::assertFalse($this->exists('messenger_messages'));
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

    // The schema manager hides the conversation and queue tables behind the schema filter, so a query is what shows whether a table is there.
    private function exists(string $table): bool
    {
        try {
            $this->connection()->fetchOne(sprintf('SELECT 1 FROM %s', $table));

            return true;
        } catch (TableNotFoundException) {
            return false;
        }
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
