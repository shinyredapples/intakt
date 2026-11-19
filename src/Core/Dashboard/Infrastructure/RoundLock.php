<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

// One key for every press that acts on a round, so a start and a stop of the same round, or two of either, never run at once (#2703, D2).
final readonly class RoundLock
{
    public const string KEY_PREFIX = 'dashboard-round-';

    public function __construct(private LockFactory $locks)
    {
    }

    // Null when another press holds the round. Not waiting, because a worker should not wait for another press. Called only with a round the ledger knows, because the file store keeps one file per key and a made-up round must leave none.
    public function tryHolding(string $round): ?LockInterface
    {
        $lock = $this->locks->createLock(self::KEY_PREFIX.$round);

        return $lock->acquire() ? $lock : null;
    }
}
