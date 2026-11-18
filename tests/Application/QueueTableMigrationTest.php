<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Doctrine\DBAL\Schema\Schema;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;

// Covers nothing because the migrations bundle loads migrations by path, so their classes do not exist when PHPUnit reads the attributes.
#[Group('persistence')]
#[CoversNothing]
final class QueueTableMigrationTest extends KernelTestCase
{
    private const string TRANSPORT_DSN = 'MESSENGER_TRANSPORT_DSN';

    private string $configuredDsn;

    // The migration writes the table by hand, since the transport's auto_setup is off; this keeps it the table the transport describes.
    public function testTheMigrationsCreateTheQueueTableTheDoctrineTransportDescribes(): void
    {
        $console = $this->console();
        $container = self::getContainer();
        EmptyDatabase::in($container);
        $console->run(['command' => 'doctrine:migrations:migrate', '--no-interaction' => true]);
        $console->assertCommandIsSuccessful($console->getDisplay());

        $connection = $container->get('doctrine.dbal.default_connection');
        $transport = $container->get('messenger.transport.async');
        assert($transport instanceof DoctrineTransport);

        $described = $transport->configureSchema(new Schema(), $connection, static fn (): bool => true)->getTable('messenger_messages');
        $manager = $connection->createSchemaManager();
        $difference = $manager->createComparator()->compareTables($manager->introspectTableByUnquotedName('messenger_messages'), $described);

        self::assertTrue($difference->isEmpty(), 'The migrated messenger_messages table differs from the one the Doctrine transport describes.');
    }

    protected function setUp(): void
    {
        $configured = $_ENV[self::TRANSPORT_DSN] ?? '';
        $this->configuredDsn = is_string($configured) ? $configured : '';
        $_ENV[self::TRANSPORT_DSN] = 'doctrine://default';
    }

    protected function tearDown(): void
    {
        $_ENV[self::TRANSPORT_DSN] = $this->configuredDsn;

        parent::tearDown();
    }

    private function console(): ApplicationTester
    {
        $application = new Application(self::bootKernel());
        $application->setAutoExit(false);

        return new ApplicationTester($application);
    }
}
