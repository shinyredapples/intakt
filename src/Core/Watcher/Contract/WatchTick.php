<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\Contract;

// In the contract, because a forge that keeps one listing per tick below its reserve must count in the same ticks.
final readonly class WatchTick
{
    public const string INTERVAL = '1 minute';
}
