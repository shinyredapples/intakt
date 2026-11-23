<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(UnsyncedSqlite::class)]
#[CoversClass(UnsyncedSqliteDriver::class)]
final class UnsyncedSqliteTest extends KernelTestCase
{
    private const int SYNCHRONOUS_OFF = 0;

    private string $directory;

    public function testASqliteFileConnectionDoesNotWaitForTheDiskAfterEveryWrite(): void
    {
        $connection = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'path' => $this->directory.'/database.sqlite'],
            new Configuration()->setMiddlewares([new UnsyncedSqlite()]),
        );

        self::assertSame(self::SYNCHRONOUS_OFF, $connection->fetchOne('PRAGMA synchronous'));
    }

    public function testAConnectionToAnotherEngineIsLeftAsItIs(): void
    {
        $connection = $this->createMock(DriverConnection::class);
        $connection->expects($this->never())->method('exec');
        $driver = self::createStub(Driver::class);
        $driver->method('connect')->willReturn($connection);
        $driver->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

        new UnsyncedSqlite()->wrap($driver)->connect([]);
    }

    public function testTheTestContainerGivesItToTheDefaultConnection(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        self::assertSame(self::SYNCHRONOUS_OFF, $connection->fetchOne('PRAGMA synchronous'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/unsynced-sqlite-'.bin2hex(random_bytes(4));
        new Filesystem()->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        new Filesystem()->remove($this->directory);
    }
}
