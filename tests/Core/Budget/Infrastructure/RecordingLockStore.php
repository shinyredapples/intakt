<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Infrastructure;

use Override;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

// Notes every save and delete, because a lock given back and taken again between two statements looks held at both statements.
final class RecordingLockStore implements PersistingStoreInterface
{
    /** @var list<string> */
    public array $events = [];

    private readonly InMemoryStore $inner;

    public function __construct()
    {
        $this->inner = new InMemoryStore();
    }

    #[Override]
    public function save(Key $key): void
    {
        $this->inner->save($key);
        $this->events[] = 'save';
    }

    #[Override]
    public function delete(Key $key): void
    {
        $this->inner->delete($key);
        $this->events[] = 'delete';
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
