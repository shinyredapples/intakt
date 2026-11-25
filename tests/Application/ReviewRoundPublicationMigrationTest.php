<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20261125100000;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

// Covers nothing because the migrations bundle loads migrations by path, so their classes do not exist when PHPUnit reads the attributes.
#[Group('persistence')]
#[CoversNothing]
final class ReviewRoundPublicationMigrationTest extends TestCase
{
    public function testARoundStoredBeforeTheMigrationReadsAsNotPublished(): void
    {
        $connection = $this->roundsOnSqlite();
        $connection->executeStatement("INSERT INTO review_round (id, state) VALUES ('0196a0a8-0000-7000-8000-000000000001', 'judged')");

        $this->up($connection);

        self::assertSame([null], $connection->fetchFirstColumn('SELECT published_at FROM review_round'));
    }

    // A rollback swaps the image and keeps the database, so the release before this one must still store its rounds on the new schema.
    public function testTheReleaseBeforeStoresARoundWithoutThePublicationColumn(): void
    {
        $connection = $this->roundsOnSqlite();
        $this->up($connection);

        $connection->executeStatement("INSERT INTO review_round (id, state) VALUES ('0196a0a8-0000-7000-8000-000000000002', 'judged')");

        self::assertSame([null], $connection->fetchFirstColumn('SELECT published_at FROM review_round'));
    }

    public function testTheWayBackDropsThePublicationColumnAndKeepsTheRounds(): void
    {
        $connection = $this->roundsOnSqlite();
        $this->up($connection);
        $connection->executeStatement("INSERT INTO review_round (id, state, published_at) VALUES ('0196a0a8-0000-7000-8000-000000000003', 'judged', '2026-11-25 10:00:00')");

        $this->down($connection);

        self::assertArrayNotHasKey('published_at', $connection->createSchemaManager()->introspectTableColumnsByUnquotedName('review_round'));
        self::assertSame(['judged'], $connection->fetchFirstColumn('SELECT state FROM review_round'));
    }

    private function roundsOnSqlite(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE review_round (id CHAR(36) NOT NULL, state VARCHAR(16) NOT NULL, PRIMARY KEY (id))');

        return $connection;
    }

    private function up(Connection $connection): void
    {
        $migration = new Version20261125100000($connection, new NullLogger());
        $migration->up($connection->createSchemaManager()->introspectSchema());
        $this->execute($migration, $connection);
    }

    private function execute(AbstractMigration $migration, Connection $connection): void
    {
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
    }

    private function down(Connection $connection): void
    {
        $migration = new Version20261125100000($connection, new NullLogger());
        $migration->down($connection->createSchemaManager()->introspectSchema());
        $this->execute($migration, $connection);
    }
}
