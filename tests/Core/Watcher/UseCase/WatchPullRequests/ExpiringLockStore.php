<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Override;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

// Lets a lock run out at a time the test sets, because the in-memory store keeps every lock until it is released, and only a forced expiry proves a lifetime long enough.
final class ExpiringLockStore implements PersistingStoreInterface
{
    public float $now = 0;
    /** @var array<string, float> */
    private array $expiresAt = [];
    /** @var array<string, Key> */
    private array $held = [];
    private readonly InMemoryStore $inner;

    public function __construct()
    {
        $this->inner = new InMemoryStore();
    }

    #[Override]
    public function save(Key $key): void
    {
        $name = (string) $key;

        if (isset($this->held[$name]) && ($this->expiresAt[$name] ?? INF) <= $this->now) {
            $this->inner->delete($this->held[$name]);
        }

        $this->inner->save($key);
        $this->held[$name] = $key;
    }

    #[Override]
    public function delete(Key $key): void
    {
        $this->inner->delete($key);
    }

    #[Override]
    public function exists(Key $key): bool
    {
        return $this->inner->exists($key);
    }

    #[Override]
    public function putOffExpiration(Key $key, float $ttl): void
    {
        $this->expiresAt[(string) $key] = $this->now + $ttl;
    }
}
