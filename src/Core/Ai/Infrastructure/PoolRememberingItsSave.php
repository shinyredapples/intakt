<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Override;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Needed because the RateLimiter drops the result of its cache save, so a refused window would still read as accepted; excluded from the container because one is built per cooldown, so no worker reads another's result.
#[Exclude]
final class PoolRememberingItsSave implements CacheItemPoolInterface
{
    private bool $saved = false;

    public function __construct(private readonly CacheItemPoolInterface $pool)
    {
    }

    public function isSaved(): bool
    {
        return $this->saved;
    }

    #[Override]
    public function getItem(string $key): CacheItemInterface
    {
        return $this->pool->getItem($key);
    }

    /**
     * @param array<string> $keys
     *
     * @return iterable<mixed>
     */
    #[Override]
    public function getItems(array $keys = []): iterable
    {
        return $this->pool->getItems($keys);
    }

    #[Override]
    public function hasItem(string $key): bool
    {
        return $this->pool->hasItem($key);
    }

    #[Override]
    public function clear(): bool
    {
        return $this->pool->clear();
    }

    #[Override]
    public function deleteItem(string $key): bool
    {
        return $this->pool->deleteItem($key);
    }

    /**
     * @param array<string> $keys
     */
    #[Override]
    public function deleteItems(array $keys): bool
    {
        return $this->pool->deleteItems($keys);
    }

    #[Override]
    public function save(CacheItemInterface $item): bool
    {
        $this->saved = $this->pool->save($item);

        return $this->saved;
    }

    #[Override]
    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->pool->saveDeferred($item);
    }

    #[Override]
    public function commit(): bool
    {
        return $this->pool->commit();
    }
}
