<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Infrastructure;

use Override;
use Symfony\Component\Lock\BlockingStoreInterface;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\Store\InMemoryStore;

// A lock another holder has is refused at once instead of waited for, because one process that waited for its own test's lock would never go on; the refusal shows that a real writer would have waited.
final readonly class RefusingToWaitStore implements BlockingStoreInterface
{
    public function __construct(private InMemoryStore $store)
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
