<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Contract;

// Dispatched once the stored event is committed, so a listener never shows an event a rolled-back transaction took back.
final readonly class LedgerEventAppended
{
    public function __construct(public RecordedEvent $recorded)
    {
    }
}
