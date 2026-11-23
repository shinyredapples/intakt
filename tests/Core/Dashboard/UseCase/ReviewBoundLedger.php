<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\Mentions;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use LogicException;

// Stands for a ledger too large to read whole: only the starts and the rows that name given texts can be read, so a read meant for one review fails loudly when it reads more.
final readonly class ReviewBoundLedger implements Ledger
{
    public function __construct(
        private Ledger $ledger,
    ) {
    }

    public function append(LedgerEvent $event): AppendResult
    {
        return $this->ledger->append($event);
    }

    public function readInOrder(): RecordedEvents
    {
        throw new LogicException('The whole ledger was read.');
    }

    public function readOfKind(LedgerEventKind $kind): RecordedEvents
    {
        if (LedgerEventKind::ReviewStarted !== $kind) {
            throw new LogicException('Every '.$kind->value.' row was read.');
        }

        return $this->ledger->readOfKind($kind);
    }

    public function readOfKindAbout(LedgerEventKind $kind, string $subject): RecordedEvents
    {
        return $this->ledger->readOfKindAbout($kind, $subject);
    }

    public function readOfKindWithoutSubject(LedgerEventKind $kind): RecordedEvents
    {
        return $this->ledger->readOfKindWithoutSubject($kind);
    }

    public function readMentioning(Mentions $texts): RecordedEvents
    {
        return $this->ledger->readMentioning($texts);
    }

    public function readOfKindsMentioning(Mentions $texts, LedgerEventKind ...$kinds): RecordedEvents
    {
        return $this->ledger->readOfKindsMentioning($texts, ...$kinds);
    }
}
