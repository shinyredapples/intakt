<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\Mentions;
use Intakt\Core\Ledger\Contract\RecordedEvents;

final readonly class AppendRefusingLedger implements Ledger
{
    public function __construct(private Ledger $reads)
    {
    }

    public function append(LedgerEvent $event): AppendResult
    {
        return AppendResult::failed('disk full');
    }

    public function readInOrder(): RecordedEvents
    {
        return $this->reads->readInOrder();
    }

    public function readOfKind(LedgerEventKind $kind): RecordedEvents
    {
        return $this->reads->readOfKind($kind);
    }

    public function readMentioning(Mentions $texts): RecordedEvents
    {
        return $this->reads->readMentioning($texts);
    }

    public function readOfKindsMentioning(Mentions $texts, LedgerEventKind ...$kinds): RecordedEvents
    {
        return $this->reads->readOfKindsMentioning($texts, ...$kinds);
    }
}
