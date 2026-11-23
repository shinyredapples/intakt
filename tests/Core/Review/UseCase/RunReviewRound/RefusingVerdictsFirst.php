<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\Mentions;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Override;

// Refuses the first stores of a verdict, because that is how a ledger answers while another reviewer's commit holds the lock.
final class RefusingVerdictsFirst implements Ledger
{
    public private(set) int $refused = 0;

    public function __construct(private readonly Ledger $inner, private readonly int $times)
    {
    }

    #[Override]
    public function append(LedgerEvent $event): AppendResult
    {
        if (LedgerEventKind::VerdictReceived === $event->kind && $this->refused < $this->times) {
            ++$this->refused;

            return AppendResult::failed('the event was not stored: LockWaitTimeoutException');
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
        return $this->inner->readMentioning($texts);
    }

    #[Override]
    public function readOfKindsMentioning(Mentions $texts, LedgerEventKind ...$kinds): RecordedEvents
    {
        return $this->inner->readOfKindsMentioning($texts, ...$kinds);
    }
}
