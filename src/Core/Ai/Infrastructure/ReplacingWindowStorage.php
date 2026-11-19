<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Override;
use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

// Fetches nothing, so the limiter starts a fresh window, and its one save overwrites the running window in place: a reader never sees the account free between a reset and the new window.
final readonly class ReplacingWindowStorage implements StorageInterface
{
    public function __construct(
        private StorageInterface $storage,
    ) {
    }

    #[Override]
    public function save(LimiterStateInterface $limiterState): void
    {
        $this->storage->save($limiterState);
    }

    #[Override]
    public function fetch(string $limiterStateId): ?LimiterStateInterface
    {
        return null;
    }

    #[Override]
    public function delete(string $limiterStateId): void
    {
        $this->storage->delete($limiterStateId);
    }
}
