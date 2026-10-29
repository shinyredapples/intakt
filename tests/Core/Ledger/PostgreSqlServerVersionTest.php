<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ledger;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Ledger\Infrastructure\PostgreSqlServerVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(PostgreSqlServerVersion::class)]
final class PostgreSqlServerVersionTest extends KernelTestCase
{
    public function testAPostgreSqlUrlNamesTheVersionOfTheNewestPostgreSqlPlatform(): void
    {
        $version = $this->serverVersionOf('postgresql://postgres@localhost:5432/intakt');

        self::assertSame('12', $version);
    }

    public function testAPostgreSqlUrlOnTheNativeDriverNamesTheVersionOfTheNewestPostgreSqlPlatform(): void
    {
        $version = $this->serverVersionOf('postgresql://postgres@localhost:5432/intakt?driver=pgsql');

        self::assertSame('12', $version);
    }

    public function testAMySqlUrlLeavesTheVersionToTheServer(): void
    {
        $version = $this->serverVersionOf('mysql://root@localhost:3306/intakt');

        self::assertNull($version);
    }

    public function testASqliteUrlLeavesTheVersionToTheServer(): void
    {
        $version = $this->serverVersionOf('sqlite:///var/data_test.db');

        self::assertNull($version);
    }

    public function testThePostgreSqlTestConnectionGetsAPostgreSqlPlatformFromTheUrlAlone(): void
    {
        $connection = $this->connectionFor('postgresql://postgres@localhost:5432/intakt');

        self::assertSame('intakt_test', $connection->getParams()['dbname'] ?? null);
        self::assertInstanceOf(PostgreSQLPlatform::class, $connection->getDatabasePlatform());
    }

    public function testThePostgreSqlTestConnectionOnTheNativeDriverGetsAPostgreSqlPlatformFromTheUrlAlone(): void
    {
        $connection = $this->connectionFor('postgresql://postgres@localhost:5432/intakt?driver=pgsql');

        self::assertSame('pgsql', $connection->getParams()['driver'] ?? null);
        self::assertInstanceOf(PostgreSQLPlatform::class, $connection->getDatabasePlatform());
    }

    public function testTheMySqlTestConnectionKeepsDetectingTheServer(): void
    {
        $connection = $this->connectionFor('mysql://root@localhost:3306/intakt');

        self::assertNull($connection->getParams()['serverVersion'] ?? null);
    }

    private function serverVersionOf(string $url): ?string
    {
        return new PostgreSqlServerVersion(new DsnParser(['postgresql' => 'pdo_pgsql', 'mysql' => 'pdo_mysql', 'sqlite' => 'pdo_sqlite']))
            ->getEnv('postgresql_server_version', 'DATABASE_URL', static fn (): string => $url);
    }

    private function connectionFor(string $url): Connection
    {
        $previous = [$_SERVER['DATABASE_URL'] ?? null, $_ENV['DATABASE_URL'] ?? null];
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = $url;

        try {
            $registry = self::getContainer()->get(ManagerRegistry::class);
            self::assertInstanceOf(ManagerRegistry::class, $registry);
            $connection = $registry->getConnection();
        } finally {
            [$_SERVER['DATABASE_URL'], $_ENV['DATABASE_URL']] = $previous;
        }

        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
