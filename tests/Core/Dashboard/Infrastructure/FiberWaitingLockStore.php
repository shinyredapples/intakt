<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Fiber;
use Override;
use Symfony\Component\Lock\BlockingStoreInterface;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\Store\InMemoryStore;

// Waits by suspending its fiber, so a test can hold one worker inside the lock while another asks for it, in one process.
final readonly class FiberWaitingLockStore implements BlockingStoreInterface
{
    private InMemoryStore $store;

    public function __construct()
    {
        $this->store = new InMemoryStore();
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
    public function save(Key $key): void
    {
        $this->store->save($key);
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
