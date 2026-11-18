<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\UseCase\RunCheckJob;

use Closure;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;

// Runs another check's save at the moment the lock is taken, so the order where it finished first is forced instead of hoped for.
final readonly class SavingWhileLocking implements PersistingStoreInterface
{
    /**
     * @param Closure(): void $otherCheckSaves
     */
    public function __construct(
        private PersistingStoreInterface $store,
        private Closure $otherCheckSaves,
    ) {
    }

    public function save(Key $key): void
    {
        ($this->otherCheckSaves)();
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
