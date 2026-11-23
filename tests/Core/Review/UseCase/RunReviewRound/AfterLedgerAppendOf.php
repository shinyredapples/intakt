<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Closure;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\Mentions;
use Intakt\Core\Ledger\Contract\RecordedEvents;

// Runs the other work right after a row of one kind was appended, so a test can force a competing writer's row in before the appender's next step.
final readonly class AfterLedgerAppendOf implements Ledger
{
    /**
     * @param Closure(): void $other
     */
    public function __construct(
        private Ledger $inner,
        private LedgerEventKind $kind,
        private Closure $other,
    ) {
    }

    public function append(LedgerEvent $event): AppendResult
    {
        $appended = $this->inner->append($event);

        if ($this->kind === $event->kind) {
            ($this->other)();
        }

        return $appended;
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
