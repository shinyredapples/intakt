<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Closure;
use Override;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

// Runs a step once just before a change lock is taken, because that is the window between reading the due heads and holding one, and only a forced order proves it closed.
final class BeforeChangeLockStore implements PersistingStoreInterface
{
    public ?Closure $beforeChangeLock = null;
    private readonly InMemoryStore $inner;

    public function __construct()
    {
        $this->inner = new InMemoryStore();
    }

    #[Override]
    public function save(Key $key): void
    {
        $step = $this->beforeChangeLock;

        if (null !== $step && str_starts_with((string) $key, 'watcher-change-')) {
            $this->beforeChangeLock = null;
            $step();
        }

        $this->inner->save($key);
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
        $this->inner->putOffExpiration($key, $ttl);
    }
}
