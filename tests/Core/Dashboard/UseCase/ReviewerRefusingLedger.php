<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\Mentions;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;

final readonly class ReviewerRefusingLedger implements Ledger
{
    public function __construct(
        private Ledger $inner,
        private string $refusedReviewer,
    ) {
    }

    public function append(LedgerEvent $event): AppendResult
    {
        $payload = json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR);

        return is_array($payload) && ($payload['reviewer'] ?? null) === $this->refusedReviewer
            ? AppendResult::failed('disk full')
            : $this->inner->append($event);
    }

    public function readInOrder(): RecordedEvents
    {
        return $this->inner->readInOrder();
    }

    public function readOfKind(LedgerEventKind $kind): RecordedEvents
    {
        return $this->inner->readOfKind($kind);
    }

    public function readOfKindAbout(LedgerEventKind $kind, string $subject): RecordedEvents
    {
        return $this->inner->readOfKindAbout($kind, $subject);
    }

    public function newestOfKindAbout(LedgerEventKind $kind, string $subject): ?RecordedEvent
    {
        return $this->inner->newestOfKindAbout($kind, $subject);
    }

    public function readOfKindWithoutSubject(LedgerEventKind $kind): RecordedEvents
    {
        return $this->inner->readOfKindWithoutSubject($kind);
    }

    public function readMentioning(Mentions $texts): RecordedEvents
    {
        return $this->inner->readMentioning($texts);
    }

    public function readOfKindsMentioning(Mentions $texts, LedgerEventKind ...$kinds): RecordedEvents
    {
        return $this->inner->readOfKindsMentioning($texts, ...$kinds);
    }
}
