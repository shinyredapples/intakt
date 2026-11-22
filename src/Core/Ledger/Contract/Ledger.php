<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Contract;

use NoDiscard;

interface Ledger
{
    #[NoDiscard('a failed append is only reported through the result')]
    public function append(LedgerEvent $event): AppendResult;

    public function readInOrder(): RecordedEvents;

    public function readOfKind(LedgerEventKind $kind): RecordedEvents;

    // Matched on the stored payload text, so a caller gets every event that names one of the texts and must still decode and check each one; no text reads nothing.
    public function readMentioning(Mentions $texts): RecordedEvents;
}
