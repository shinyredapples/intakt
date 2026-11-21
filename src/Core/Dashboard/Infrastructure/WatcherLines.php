<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Ledger\Contract\LedgerEventKind;

// The watcher lines that replace a pull request's current state: the latest one decides in LedgerWaitingHeads, LedgerFailingHeads and LedgerQueuedRounds, so a new watcher event added here reaches every view.
final readonly class WatcherLines
{
    public const array ALL = [
        LedgerEventKind::HeadPending,
        LedgerEventKind::HeadRed,
        LedgerEventKind::HeadFailed,
        LedgerEventKind::HeadHandedOver,
        LedgerEventKind::RoundQueued,
        LedgerEventKind::HeadForgotten,
    ];
}
