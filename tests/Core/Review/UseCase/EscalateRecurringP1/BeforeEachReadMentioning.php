<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\EscalateRecurringP1;

use Closure;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\Mentions;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Override;

// Runs the other work right before each read of the events naming some rounds, because the reader has chosen its rounds by then and a round opened now makes that choice stale.
final class BeforeEachReadMentioning implements Ledger
{
    private int $reads = 0;

    /**
     * @param Closure(int): void $other
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

    #[Override]
    public function readOfKindAbout(LedgerEventKind $kind, string $subject): RecordedEvents
    {
        return $this->inner->readOfKindAbout($kind, $subject);
    }

    #[Override]
    public function readOfKindWithoutSubject(LedgerEventKind $kind): RecordedEvents
    {
        return $this->inner->readOfKindWithoutSubject($kind);
    }

    #[Override]
    public function readMentioning(Mentions $texts): RecordedEvents
    {
        ($this->other)(++$this->reads);

        return $this->inner->readMentioning($texts);
    }
}
