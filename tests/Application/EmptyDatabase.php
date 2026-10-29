<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class EmptyDatabase
{
    // The mapped tables go through the schema tool; the conversation table is outside the mapping (the schema filter hides it), so it is dropped by name.
    public static function in(ContainerInterface $container): void
    {
        $registry = $container->get('doctrine');
        assert($registry instanceof ManagerRegistry);
        $manager = $registry->getManager();
        assert($manager instanceof EntityManagerInterface);
        new SchemaTool($manager)->dropDatabase();

        $connection = $container->get('doctrine.dbal.default_connection');
        assert($connection instanceof Connection);
        $connection->executeStatement('DROP TABLE IF EXISTS reviewer_conversation');
    }
}
