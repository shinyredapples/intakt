<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Closure;
use Override;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;
use Throwable;

// Runs a step once just before a change lock is taken, because that is the window between reading the due heads and holding one, and only a forced order proves it closed.
final class BeforeChangeLockStore implements PersistingStoreInterface
{
    public ?Closure $beforeChangeLock = null;
    public ?Throwable $deleteFailure = null;
    /** @var list<string> */
    public array $changeLocksGivenBack = [];
    private readonly InMemoryStore $inner;

    public function __construct()
    {
        $this->inner = new InMemoryStore();
    }

    #[Override]
    public function save(Key $key): void
    {
        $step = $this->beforeChangeLock;

        if ($step instanceof Closure && str_starts_with((string) $key, 'watcher-change-')) {
            $this->beforeChangeLock = null;
            $step($key);
        }

        $this->inner->save($key);
    }

    #[Override]
    public function delete(Key $key): void
    {
        $failure = $this->deleteFailure;

        // Thrown once, because the lock's destructor releases it again later, and a store that still failed then would throw outside any test.
        if ($failure instanceof Throwable && str_starts_with((string) $key, 'watcher-change-')) {
            $this->deleteFailure = null;

            throw $failure;
        }

        $this->inner->delete($key);

        if (str_starts_with((string) $key, 'watcher-change-')) {
            $this->changeLocksGivenBack[] = (string) $key;
        }
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
