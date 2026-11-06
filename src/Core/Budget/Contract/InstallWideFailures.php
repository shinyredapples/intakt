<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use Throwable;

// Only causes Budget itself decides start the failure back-off, because one item's failure alone must never hold the whole install.
interface InstallWideFailures
{
    public function databaseFailed(Throwable $failure): void;

    public function lockStoreFailed(Throwable $failure): void;

    // A full disk reaches every item, and the same error on two items points at the install rather than at one item, so only those two start it; any other failure is only recorded.
    public function itemFailed(string $item, Throwable $failure): void;
}
