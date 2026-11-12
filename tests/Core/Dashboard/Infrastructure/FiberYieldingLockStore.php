<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Fiber;
use Override;
use Symfony\Component\Lock\BlockingStoreInterface;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\Store\InMemoryStore;

// A waiting acquire suspends the fiber it runs in, because a test forces the order of two workers in one process, where a wait that sleeps would never see the holder let go.
final readonly class FiberYieldingLockStore implements BlockingStoreInterface
{
    public function __construct(private InMemoryStore $store = new InMemoryStore())
    {
    }

    #[Override]
    public function save(Key $key): void
    {
        $this->store->save($key);
    }

    #[Override]
    public function waitAndSave(Key $key): void
    {
        while (true) {
            try {
                $this->store->save($key);

                return;
            } catch (LockConflictedException) {
                Fiber::suspend();
            }
        }
    }

    #[Override]
    public function delete(Key $key): void
    {
        $this->store->delete($key);
    }

    #[Override]
    public function exists(Key $key): bool
    {
        return $this->store->exists($key);
    }

    #[Override]
    public function putOffExpiration(Key $key, float $ttl): void
    {
        $this->store->putOffExpiration($key, $ttl);
    }
}
