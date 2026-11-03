<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Closure;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvents;

// Runs the other work right after the ledger was read, so a test can force a commit between a reader's ledger read and its next read.
final readonly class AfterLedgerRead implements Ledger
{
    /**
     * @param Closure(): void $other
     */
    public function __construct(
        private Ledger $inner,
        private Closure $other,
    ) {
    }

    public function append(LedgerEvent $event): AppendResult
    {
        return $this->inner->append($event);
    }

    public function readInOrder(): RecordedEvents
    {
        $read = $this->inner->readInOrder();
        ($this->other)();

        return $read;
    }

    public function readOfKind(LedgerEventKind $kind, LedgerEventKind ...$more): RecordedEvents
    {
        $read = $this->inner->readOfKind($kind, ...$more);
        ($this->other)();

        return $read;
    }
}
