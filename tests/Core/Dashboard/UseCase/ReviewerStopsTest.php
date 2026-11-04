<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\UseCase\StopReview\ReviewerStops;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewerStops::class)]
#[UsesClass(LedgerReviewPages::class)]
final class ReviewerStopsTest extends TestCase
{
    public function testAFailedAppendGivesAnErrorLine(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $ledger->startReviewer('round-1', 'security');
        $failing = new AppendRefusingLedger($ledger);
        $stops = new ReviewerStops(new LedgerReviewPages($failing), $failing);

        self::assertSame(['error', 'The stop request could not be recorded in the ledger.'], $stops->requestStopOf('round-1', 'round-1-security'));
    }
}
