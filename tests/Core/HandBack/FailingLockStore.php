<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack;

use Override;
use Symfony\Component\Lock\Exception\LockStorageException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;

final class FailingLockStore implements PersistingStoreInterface
{
    #[Override]
    public function save(Key $key): void
    {
        throw new LockStorageException('the lock store is down');
    }

    #[Override]
    public function delete(Key $key): void
    {
    }

    #[Override]
    public function exists(Key $key): bool
    {
        return false;
    }

    #[Override]
    public function putOffExpiration(Key $key, float $ttl): void
    {
    }
}
