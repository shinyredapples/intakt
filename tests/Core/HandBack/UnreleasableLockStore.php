<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack;

use Override;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;

final class UnreleasableLockStore implements PersistingStoreInterface
{
    #[Override]
    public function save(Key $key): void
    {
    }

    #[Override]
    public function delete(Key $key): void
    {
        throw new LockReleasingException('the lock store cannot release');
    }

    #[Override]
    public function exists(Key $key): bool
    {
        return true;
    }

    #[Override]
    public function putOffExpiration(Key $key, float $ttl): void
    {
    }
}
