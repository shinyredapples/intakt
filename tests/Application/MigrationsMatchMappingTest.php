<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

// Covers nothing because the migrations bundle loads migrations by path, so their classes do not exist when PHPUnit reads the attributes.
#[Group('persistence')]
#[CoversNothing]
final class MigrationsMatchMappingTest extends KernelTestCase
{
    // Runs through the application, because the migrations bundle hides its own version table from the validator only on console events.
    public function testTheMigrationsBuildTheSchemaTheMappingDescribes(): void
    {
        $console = $this->console();
        $this->emptyDatabase();

        $console->run(['command' => 'doctrine:migrations:migrate', '--no-interaction' => true]);
        $console->assertCommandIsSuccessful($console->getDisplay());

        $console->run(['command' => 'doctrine:schema:validate', '--skip-mapping' => true]);
        $console->assertCommandIsSuccessful($console->getDisplay());
    }

    private function console(): ApplicationTester
    {
        $application = new Application(self::bootKernel());
        $application->setAutoExit(false);

        return new ApplicationTester($application);
    }

    private function emptyDatabase(): void
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $manager = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        new SchemaTool($manager)->dropDatabase();
    }
}
