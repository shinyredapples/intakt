<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
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

    #[Override]
    public function readMentioning(array $texts): RecordedEvents
    {
        return $this->ledger->readMentioning($texts);
    }

    /**
     * @param list<LedgerEventKind> $kinds
     * @param list<string>          $texts
     */
    #[Override]
    public function readOfKindsMentioning(array $kinds, array $texts): RecordedEvents
    {
        return $this->ledger->readOfKindsMentioning($kinds, $texts);
    }
}
