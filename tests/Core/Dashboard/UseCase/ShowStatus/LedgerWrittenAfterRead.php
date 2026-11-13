<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ShowStatus;

use Closure;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvents;

// Forces the order in which the watcher appends a line between two reads of one status, after the given read returned its rows.
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

    public function readInOrder(): RecordedEvents
    {
        $events = $this->ledger->readInOrder();

        if ($this->writeAfter === ++$this->reads) {
            ($this->write)();
        }

        return $events;
    }
}
