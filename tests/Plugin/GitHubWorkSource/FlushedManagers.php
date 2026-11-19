<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreFlushEventArgs;
use WeakReference;

// Keeps every flushed entity manager weakly, because a test must see whether a tick's manager is freed without holding it itself.
final class FlushedManagers
{
    /** @var list<WeakReference<EntityManagerInterface>> */
    private array $managers = [];

    public function preFlush(PreFlushEventArgs $args): void
    {
        $this->managers[] = WeakReference::create($args->getObjectManager());
    }

    public function flushed(): int
    {
        return count($this->managers);
    }

    public function alive(): int
    {
        return count(array_filter($this->managers, static fn (WeakReference $manager): bool => $manager->get() instanceof EntityManagerInterface));
    }
}
