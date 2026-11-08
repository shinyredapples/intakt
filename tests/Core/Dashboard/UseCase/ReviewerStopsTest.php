<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Dashboard\UseCase\StopReview\ReviewerStops;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\ReviewerStarted;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewerStops::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(RoundPayloads::class)]
#[UsesClass(LedgerPayloads::class)]
#[UsesClass(ReviewerStarted::class)]
#[UsesClass(RoundLedgerReading::class)]
#[UsesClass(ConfiguredPrices::class)]
final class ReviewerStopsTest extends TestCase
{
    public function testAFailedAppendGivesAnErrorLine(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $ledger->startReviewer('round-1', 'security');
        $failing = new AppendRefusingLedger($ledger);

        self::assertSame(['error', 'The stop request could not be recorded in the ledger.'], $this->stopsOn($failing)->requestStopOf('round-1', 'round-1-security'));
    }

    public function testAReviewerStartedInTodaysFormatCanBeAskedToStop(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $ledger->append(new LedgerEvent(LedgerEventKind::ReviewerStarted, StoredSerializerFactory::create()->serialize(new ReviewerStarted('round-1', 'round-1-security', 'security', Tier::Strong), 'json')));

        self::assertSame(['success', 'Asked the reviewer to stop.'], $this->stopsOn($ledger)->requestStopOf('round-1', 'round-1-security'));
    }

    public function testAReviewerWhoseStartedRowDoesNotDeserializeCannotBeAskedToStop(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $ledger->append(new LedgerEvent(LedgerEventKind::ReviewerStarted, '{"round":"round-1","reviewer":"round-1-security","role":"security","tier":"mighty"}'));

        self::assertNull($this->stopsOn($ledger)->requestStopOf('round-1', 'round-1-security'));
    }

    private function stopsOn(Ledger $ledger): ReviewerStops
    {
        return new ReviewerStops(new LedgerReviewPages($ledger, new RoundPayloads(new LedgerPayloads(StoredSerializerFactory::create())), new RoundLedgerReading($ledger, new LedgerPayloads(StoredSerializerFactory::create()), new ConfiguredPrices([]))), $ledger);
    }
}
