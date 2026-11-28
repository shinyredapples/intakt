<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\Mentions;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Override;

// Counts the reads of each kind, so a test can tell how often a reader scanned the judge_done events.
final class CountingLedger implements Ledger
{
    /** @var array<string, int> */
    public array $readsOfKind = [];

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
        return $this->ledger->readInOrder();
    }

    #[Override]
    public function readOfKind(LedgerEventKind $kind): RecordedEvents
    {
        $this->readsOfKind[$kind->value] = ($this->readsOfKind[$kind->value] ?? 0) + 1;

        return $this->ledger->readOfKind($kind);
    }

    public function readOfKindAbout(LedgerEventKind $kind, string $subject): RecordedEvents
    {
        return $this->ledger->readOfKindAbout($kind, $subject);
    }

    public function newestOfKindAbout(LedgerEventKind $kind, string $subject): ?RecordedEvent
    {
        return $this->ledger->newestOfKindAbout($kind, $subject);
    }

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
