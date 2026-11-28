<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\Contract;

use Intakt\Core\Ledger\Contract\LedgerEventKind;

// Dispatched after the watcher appended its line, maybe inside a transaction not yet committed, so a view that shows the watcher's state (a failing or handed-over head) can update without the watcher knowing the view.
final readonly class WatcherLineRecorded
{
    public function __construct(public LedgerEventKind $kind)
    {
    }
}
