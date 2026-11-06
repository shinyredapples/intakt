<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use Throwable;

// Only the causes Budget itself decides start the failure back-off: one item's failure never does, unless a second item hits the same error.
interface InstallWideFailures
{
    public function databaseFailed(Throwable $failure): void;

    public function lockStoreFailed(Throwable $failure): void;

    // Starts the back-off for a full disk, or when another item failed with the same error within the last hour; any other failure is only recorded.
    public function itemFailed(string $item, Throwable $failure): void;
}
