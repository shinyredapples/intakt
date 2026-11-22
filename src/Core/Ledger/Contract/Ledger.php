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

    // Matched on the subject the event was appended with, exactly, so an event appended without one is never read here.
    public function readOfKindAbout(LedgerEventKind $kind, string $subject): RecordedEvents;

    // The events of the kind that were appended without a subject, such as those stored before the ledger had one.
    public function readOfKindWithoutSubject(LedgerEventKind $kind): RecordedEvents;

    // Matched on the stored payload text, so a caller gets every event that names one of the texts and must still decode and check each one; no text reads nothing.
    /**
     * @param list<string> $texts
     */
    public function readMentioning(array $texts): RecordedEvents;
}
