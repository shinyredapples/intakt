<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watchdog;

use Closure;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Override;

// Runs a step once, just before the first clear is appended, so a test forces what happens between a clear's read of the card and its append.
final class BeforeTheClear implements Ledger
{
    private ?Closure $first = null;

    public function __construct(private readonly Ledger $inner)
    {
    }

    public function runFirst(Closure $step): void
    {
        $this->first = $step;
    }

    #[Override]
    public function append(LedgerEvent $event): AppendResult
    {
        $step = $this->first;
        if (LedgerEventKind::HaltCleared === $event->kind && $step instanceof Closure) {
            $this->first = null;
            $step();
        }

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
        return $this->inner->readMentioning($texts);
    }
}
