<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Contract;

use NoDiscard;

interface Ledger
{
    #[NoDiscard('a failed append is only reported through the result')]
    public function append(LedgerEvent $event): AppendResult;

    public function readInOrder(): RecordedEvents;

    public function readOfKind(LedgerEventKind $kind, LedgerEventKind ...$more): RecordedEvents;
}
