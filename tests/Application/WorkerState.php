<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

// Read from the container without starting a request, because a probe that ran one would reset the state it looks for.
final readonly class WorkerState
{
    public function __construct(
        public ?string $user,
        public int $loadedEntities,
    ) {
    }

    // Through the test container, because the services read here are private in the compiled one.
    public static function in(KernelInterface $kernel): self
    {
        $container = $kernel->getContainer()->get('test.service_container');
        assert($container instanceof ContainerInterface);
        $tokens = $container->get('security.token_storage');
        $manager = $container->get('doctrine.orm.default_entity_manager');
        assert($tokens instanceof TokenStorageInterface && $manager instanceof EntityManagerInterface);

        return new self($tokens->getToken()?->getUserIdentifier(), self::countEntities($manager));
    }

    private static function countEntities(EntityManagerInterface $manager): int
    {
        return array_sum(array_map(count(...), $manager->getUnitOfWork()->getIdentityMap()));
    }
}
