<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20261120100000;
use DoctrineMigrations\Version20261120120000;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

// Covers nothing because the migrations bundle loads migrations by path, so their classes do not exist when PHPUnit reads the attributes.
#[Group('persistence')]
#[CoversNothing]
final class IntakeGenerationMigrationTest extends KernelTestCase
{
    public function testADryRunPlansCreatingTheIntakeTablesAndAddingTheGenerationTogether(): void
    {
        EmptyDatabase::in(self::getContainer());
        $application = new Application(self::bootKernel());
        $application->setAutoExit(false);
        $console = new ApplicationTester($application);

        $console->run([
            'command' => 'doctrine:migrations:execute',
            'versions' => [Version20261120100000::class, Version20261120120000::class],
            '--up' => true,
            '--dry-run' => true,
            '--no-interaction' => true,
        ]);

        $console->assertCommandIsSuccessful($console->getDisplay());
    }

    public function testAStoredValidatorGetsTheFirstGenerationAndSurvivesTheWayBackWhileARowWithoutOneGoes(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->apply(new Version20261120100000($connection, new NullLogger()), $connection, up: true);
        $connection->executeStatement("INSERT INTO work_source_validator (project, validator) VALUES ('old', 'W/\"a\"')");

        $this->apply(new Version20261120120000($connection, new NullLogger()), $connection, up: true);
        $generation = $connection->fetchOne("SELECT generation FROM work_source_validator WHERE project = 'old'");
        $connection->executeStatement("INSERT INTO work_source_validator (project, validator) VALUES ('new', NULL)");
        $this->apply(new Version20261120120000($connection, new NullLogger()), $connection, up: false);

        self::assertSame(1, (int) $generation);
        self::assertSame(['old' => 'W/"a"'], $connection->fetchAllKeyValue('SELECT project, validator FROM work_source_validator'));
    }

    private function apply(AbstractMigration $migration, Connection $connection, bool $up): void
    {
        $up ? $migration->up(new Schema()) : $migration->down(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
    }
}
