<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\SizeTask;

use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\Mentions;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Override;

// The fixture's ledger, refusing appends of one kind while a test says so, so a split can be stopped right at one of its records and resumed on the same ledger.
final class KindRefusingLedger implements Ledger
{
    public ?LedgerEventKind $refused = null;

    public function __construct(private readonly Ledger $ledger)
    {
    }

    #[Override]
    public function append(LedgerEvent $event): AppendResult
    {
        return $event->kind === $this->refused ? AppendResult::failed('the ledger is down') : $this->ledger->append($event);
    }

    #[Override]
    public function readInOrder(): RecordedEvents
    {
        return $this->ledger->readInOrder();
    }

    #[Override]
    public function readOfKind(LedgerEventKind $kind): RecordedEvents
    {
        return $this->ledger->readOfKind($kind);
    }

    #[Override]
    public function readOfKindAbout(LedgerEventKind $kind, string $subject): RecordedEvents
    {
        return $this->ledger->readOfKindAbout($kind, $subject);
    }

    #[Override]
    public function newestOfKindAbout(LedgerEventKind $kind, string $subject): ?RecordedEvent
    {
        return $this->ledger->newestOfKindAbout($kind, $subject);
    }

    #[Override]
    public function readOfKindWithoutSubject(LedgerEventKind $kind): RecordedEvents
    {
        return $this->ledger->readOfKindWithoutSubject($kind);
    }

    #[Override]
    public function readMentioning(Mentions $texts): RecordedEvents
    {
        return $this->ledger->readMentioning($texts);
    }

    #[Override]
    public function readOfKindsMentioning(Mentions $texts, LedgerEventKind ...$kinds): RecordedEvents
    {
        return $this->ledger->readOfKindsMentioning($texts, ...$kinds);
    }
}
