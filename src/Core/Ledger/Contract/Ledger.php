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
    /**
     * @param list<string> $texts
     */
    public function readMentioning(array $texts): RecordedEvents;

    // Narrowed by kind in the query, because a caller that needs only some kinds of a busy project must not read its other lines; no kind or no text reads nothing.
    /**
     * @param list<LedgerEventKind> $kinds
     * @param list<string>          $texts
     */
    public function readOfKindsMentioning(array $kinds, array $texts): RecordedEvents;
}
