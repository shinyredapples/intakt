<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use Throwable;

// The only way into the failure back-off, because one item's failure once held every head of a lane for 40 minutes: it starts only on a cause no item can avoid.
interface InstallWideFailures
{
    // The item is the caller's key for what failed, the same on every failure of it, so its own repeated failures never count as two items.
    public function itemFailed(string $item, Throwable $failure): void;

    // Apart from itemFailed, because a lock store can fail while a tick takes or gives back its items' locks, outside any item's step.
    public function lockStoreFailed(Throwable $failure): void;

    public function handOverFailed(Throwable $failure): void;
}
