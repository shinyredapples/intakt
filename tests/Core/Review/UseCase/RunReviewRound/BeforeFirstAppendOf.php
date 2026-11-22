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
use Override;

// Runs the other work once, right before the first append of one kind, because the appender's transaction has read its row by then and the other writer's commit makes that snapshot stale.
final class BeforeFirstAppendOf implements Ledger
{
    /** @var list<string> */
    public private(set) array $failures = [];
    private bool $ran = false;

    /**
     * @param Closure(): void $other
     */
    public function __construct(
        private readonly Ledger $inner,
        private readonly LedgerEventKind $kind,
        private readonly Closure $other,
    ) {
    }

    #[Override]
    public function append(LedgerEvent $event): AppendResult
    {
        if (!$this->ran && $this->kind === $event->kind) {
            $this->ran = true;
            ($this->other)();
        }

        $appended = $this->inner->append($event);

        if (!$appended->succeeded()) {
            $this->failures[] = (string) $appended->failure;
        }

        return $appended;
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
}
