<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\EscalateRecurringP1;

use Closure;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Override;

// Runs the other work right before each read of the events naming some rounds, because the reader has chosen its rounds by then and a round opened now makes that choice stale.
final class BeforeEachReadMentioning implements Ledger
{
    private int $reads = 0;

    /**
     * @param Closure(int): void $other given the number of the read, from 1
     */
    public function __construct(
        private readonly Ledger $inner,
        private readonly Closure $other,
    ) {
    }

    #[Override]
    public function append(LedgerEvent $event): AppendResult
    {
        return $this->inner->append($event);
    }

    #[Override]
    public function readInOrder(): RecordedEvents
    {
        return $this->inner->readInOrder();
    }

    #[Override]
    public function readOfKind(LedgerEventKind $kind): RecordedEvents
    {
        return $this->inner->readOfKind($kind);
    }

    /**
     * @param list<string> $texts
     */
    #[Override]
    public function readMentioning(array $texts): RecordedEvents
    {
        ($this->other)(++$this->reads);

        return $this->inner->readMentioning($texts);
    }
}
