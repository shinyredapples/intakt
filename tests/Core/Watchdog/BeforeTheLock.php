<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watchdog;

use Closure;
use Override;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;

// Runs a step once, just before the first lock is taken, so a test forces another guard to finish between a guard's read of main and its records.
final class BeforeTheLock implements PersistingStoreInterface
{
    private ?Closure $first = null;

    public function __construct(private readonly PersistingStoreInterface $inner)
    {
    }

    public function runFirst(Closure $step): void
    {
        $this->first = $step;
    }

    #[Override]
    public function save(Key $key): void
    {
        $step = $this->first;
        if ($step instanceof Closure) {
            $this->first = null;
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
