<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\UseCase\StopReview\ReviewerStops;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewerStops::class)]
#[UsesClass(LedgerReviewPages::class)]
final class ReviewerStopsTest extends TestCase
{
    public function testAFailedAppendGivesAnErrorLine(): void
    {
        $inner = new DashboardLedger();
        $inner->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $inner->startReviewer('round-1', 'security');
        $failing = new class($inner) implements Ledger {
            public function __construct(private readonly DashboardLedger $inner)
            {
            }

            public function append(LedgerEvent $event): AppendResult
            {
                return AppendResult::failed('disk full');
            }

            public function readInOrder(): RecordedEvents
            {
                return $this->inner->readInOrder();
            }

            public function readOfKind(LedgerEventKind $kind): RecordedEvents
            {
                return $this->inner->readOfKind($kind);
            }
        };
        $stops = new ReviewerStops(new LedgerReviewPages($failing), $failing);

        self::assertSame(['error', 'The stop request could not be recorded in the ledger.'], $stops->requestStopOf('round-1', 'round-1-security'));
    }
}
