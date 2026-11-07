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

    // Apart from itemFailed, because every item reads and writes the forge with the same credentials, so the first refusal already holds for all of them.
    public function forgeCredentialsRefused(Throwable $failure): void;

    // Apart from itemFailed, because a lost hand-over leaves an item that keeps failing with nobody told to look at it, and the next hand-over would most likely be lost the same way.
    public function handOverFailed(Throwable $failure): void;
}
