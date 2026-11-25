<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

// Stands for a lock another worker holds for the next few tries, because two processes cannot overlap inside one test.
final class BusyForAWhileStore implements PersistingStoreInterface
{
    public int $tries = 0;

    private readonly InMemoryStore $store;

    public function __construct(private readonly int $busyFor)
    {
        $this->store = new InMemoryStore();
    }

    public function save(Key $key): void
    {
        ++$this->tries;
        if ($this->tries <= $this->busyFor) {
            throw new LockConflictedException();
        }

        $this->store->save($key);
    }

    public function delete(Key $key): void
    {
        $this->store->delete($key);
    }

    public function exists(Key $key): bool
    {
        return $this->store->exists($key);
    }

    public function putOffExpiration(Key $key, float $ttl): void
    {
        $this->store->putOffExpiration($key, $ttl);
    }
}
