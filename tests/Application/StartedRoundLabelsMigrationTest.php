<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Watcher\Domain\StartedRound;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

// Covers nothing because the migrations bundle loads migrations by path, so their classes do not exist when PHPUnit reads the attributes.
#[Group('persistence')]
#[CoversNothing]
final class StartedRoundLabelsMigrationTest extends KernelTestCase
{
    // A rollback swaps the image and keeps the database, so the release before this one must still store its rounds on the new schema.
    public function testARoundTheReleaseBeforeStoresWithoutLabelsIsStoredAndReadsAsNoLabels(): void
    {
        $application = new Application(self::bootKernel());
        $application->setAutoExit(false);
        $container = self::getContainer();
        EmptyDatabase::in($container);
        $console = new ApplicationTester($application);
        $console->run(['command' => 'doctrine:migrations:migrate', '--no-interaction' => true]);
        $console->assertCommandIsSuccessful($console->getDisplay());
        $connection = $container->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        $connection->executeStatement("INSERT INTO watcher_started_round (project, change_ref, head, round_id, stale, queued_recorded) VALUES ('alpha', '7', 'bbbb', 'round-0', false, false)");

        $entities = $container->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $round = $entities->find(StartedRound::class, ['project' => 'alpha', 'change' => '7', 'head' => 'bbbb']);
        self::assertInstanceOf(StartedRound::class, $round);
        self::assertSame([], [...$round->labels()]);
    }
}
