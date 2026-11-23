<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ReviewChange;

use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\Mentions;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Override;

// Counts every read, so a test can tell whether a reader scans the ledger once per round or once per reviewer.
final class ReadCountingLedger implements Ledger
{
    public int $reads = 0;

    public function __construct(private readonly Ledger $ledger)
    {
    }

    #[Override]
    public function append(LedgerEvent $event): AppendResult
    {
        return $this->ledger->append($event);
    }

    #[Override]
    public function readInOrder(): RecordedEvents
    {
        ++$this->reads;

        return $this->ledger->readInOrder();
    }

    #[Override]
    public function readOfKind(LedgerEventKind $kind): RecordedEvents
    {
        ++$this->reads;

        return $this->ledger->readOfKind($kind);
    }

    #[Override]
    public function readOfKindAbout(LedgerEventKind $kind, string $subject): RecordedEvents
    {
        ++$this->reads;

        return $this->ledger->readOfKindAbout($kind, $subject);
    }

    #[Override]
    public function readOfKindWithoutSubject(LedgerEventKind $kind): RecordedEvents
    {
        ++$this->reads;

        return $this->ledger->readOfKindWithoutSubject($kind);
    }

    #[Override]
    public function readMentioning(Mentions $texts): RecordedEvents
    {
        ++$this->reads;

        return $this->ledger->readMentioning($texts);
    }

    #[Override]
    public function readOfKindsMentioning(Mentions $texts, LedgerEventKind ...$kinds): RecordedEvents
    {
        ++$this->reads;

        return $this->ledger->readOfKindsMentioning($texts, ...$kinds);
    }
}
