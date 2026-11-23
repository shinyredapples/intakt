<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Stored;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use DoctrineMigrations\Version20261123100000;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[Group('persistence')]
#[CoversClass(Version20261123100000::class)]
final class InterruptedReviewerRollbackTest extends TestCase
{
    public function testRollingBackPutsAnInterruptedReviewerBackToRunningForThePreviousRelease(): void
    {
        $db = $this->migratedTable();
        $db->update('round_reviewer', ['state' => 'interrupted', 'agent_session_id' => 'example-session'], ['id' => 1]);

        $this->rollBack($db);

        self::assertSame('running', $db->fetchOne('SELECT state FROM round_reviewer WHERE id = 1'));
    }

    public function testRollingBackLeavesEveryOtherReviewerStateAsItWas(): void
    {
        $db = $this->migratedTable();
        $db->insert('round_reviewer', ['id' => 2, 'state' => 'verdict']);

        $this->rollBack($db);

        self::assertSame(['running', 'verdict'], $db->fetchFirstColumn('SELECT state FROM round_reviewer ORDER BY id'));
    }

    public function testMigratingAddsAnEmptySessionAndNoInterruptionsToAStoredReviewer(): void
    {
        $db = $this->migratedTable();

        self::assertSame(
            ['agent_session_id' => null, 'interruptions' => '0'],
            $db->fetchAssociative('SELECT agent_session_id, CAST(interruptions AS TEXT) AS interruptions FROM round_reviewer WHERE id = 1'),
        );
    }

    public function testMigratingPutsAStoredReviewerAtTheFirstPanelPosition(): void
    {
        $db = $this->migratedTable();

        self::assertSame('0', $db->fetchOne('SELECT CAST(panel_position AS TEXT) FROM round_reviewer WHERE id = 1'));
    }

    public function testRollingBackDropsThePanelPosition(): void
    {
        $db = $this->migratedTable();

        $this->rollBack($db);

        self::assertSame(['id', 'state'], $db->fetchFirstColumn("SELECT name FROM pragma_table_info('round_reviewer') ORDER BY cid"));
    }

    private function migratedTable(): Connection
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE round_reviewer (id INTEGER PRIMARY KEY, state VARCHAR(16) NOT NULL)');
        $db->insert('round_reviewer', ['id' => 1, 'state' => 'running']);

        $migration = new Version20261123100000($db, new NullLogger());
        $migration->up($db->createSchemaManager()->introspectSchema());
        $this->apply($db, $migration);

        return $db;
    }

    private function rollBack(Connection $db): void
    {
        $migration = new Version20261123100000($db, new NullLogger());
        $migration->down($db->createSchemaManager()->introspectSchema());
        $this->apply($db, $migration);
    }

    private function apply(Connection $db, Version20261123100000 $migration): void
    {
        foreach ($migration->getSql() as $query) {
            $db->executeStatement($query->getStatement());
        }
    }
}
