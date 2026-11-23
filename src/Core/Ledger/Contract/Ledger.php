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

    // Kept apart from readOfKindAbout(), because rows stored before the ledger had a subject carry none and are never back-filled, so a caller reads them only as a fallback.
    public function readOfKindWithoutSubject(LedgerEventKind $kind): RecordedEvents;

    // Matched on the stored payload text, so a caller gets every event that names one of the texts and must still decode and check each one; no text reads nothing.
    public function readMentioning(Mentions $texts): RecordedEvents;

    // Narrowed by kind in the query, because a caller that needs only some kinds of a busy project must not read its other lines; no kind or no text reads nothing.
    public function readOfKindsMentioning(Mentions $texts, LedgerEventKind ...$kinds): RecordedEvents;
}
