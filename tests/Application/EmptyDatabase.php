<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class EmptyDatabase
{
    // The mapped tables go through the schema tool; the conversation and queue tables are outside the mapping (the schema filter hides them), so they are dropped by name.
    public static function in(ContainerInterface $container): void
    {
        $registry = $container->get('doctrine');
        $manager = $registry->getManager();
        assert($manager instanceof EntityManagerInterface);
        new SchemaTool($manager)->dropDatabase();

        $connection = $container->get('doctrine.dbal.default_connection');
        $connection->executeStatement('DROP TABLE IF EXISTS reviewer_conversation');
        $connection->executeStatement('DROP TABLE IF EXISTS messenger_messages');
    }
}
