<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Closure;
use Override;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

// A lock store that lets a reader step in each time a writer lets a lock go, because that is the only moment another worker's reader, which waits for the same locks, can look at the window.
final class ReadingOnEveryRelease implements PersistingStoreInterface
{
    public ?Closure $read = null;

    private readonly InMemoryStore $store;

    private bool $reading = false;

    public function __construct()
    {
        $this->store = new InMemoryStore();
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
        $read = $this->read;
        if ($this->reading || !$read instanceof Closure) {
            return;
        }

        $this->reading = true;

        try {
            $read();
        } finally {
            $this->reading = false;
        }
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
