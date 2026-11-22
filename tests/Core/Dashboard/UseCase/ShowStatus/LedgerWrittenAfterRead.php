<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ShowStatus;

use Closure;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\Mentions;
use Intakt\Core\Ledger\Contract\RecordedEvents;

// The watcher appends without waiting for a status read to end, so a line can land between two reads of the same status.
final class LedgerWrittenAfterRead implements Ledger
{
    private int $reads = 0;

    public function __construct(private readonly Ledger $ledger, private readonly int $writeAfter, private readonly Closure $write)
    {
    }

    public function append(LedgerEvent $event): AppendResult
    {
        return $this->ledger->append($event);
    }

    public function readOfKind(LedgerEventKind $kind): RecordedEvents
    {
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

    public function readInOrder(): RecordedEvents
    {
        $events = $this->ledger->readInOrder();

        if ($this->writeAfter === ++$this->reads) {
            ($this->write)();
        }

        return $events;
    }
}
