<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\Infrastructure\RoundLock;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Dashboard\UseCase\StopReview\ReviewerStops;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\ReviewerStarted;
use Intakt\Core\Review\Contract\RoundEnding;
use Intakt\Core\Review\Contract\RoundEndings;
use Intakt\Core\Review\Contract\RoundEnds;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Tests\Support\FixedRoundEndings;
use Intakt\Tests\Support\ReplayMarkBus;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

#[CoversClass(ReviewerStops::class)]
#[UsesClass(RoundLock::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(ReplayMarks::class)]
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
        $ledger->append(new LedgerEvent(LedgerEventKind::ReviewerStarted, StoredSerializerFactory::create()->serialize(new ReviewerStarted('round-1', 'round-1-security', 'security', Tier::Strong), JsonEncoder::FORMAT)));

        self::assertSame(['success', 'Asked the reviewer to stop.'], $this->stopsOn($ledger)->requestStopOf('round-1', 'round-1-security'));
    }

    public function testAReviewerWhoseStartedRowDoesNotDeserializeGetsTheNoReviewerRunsLine(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $ledger->append(new LedgerEvent(LedgerEventKind::ReviewerStarted, '{"round":"round-1","reviewer":"round-1-security","role":"security","tier":"mighty"}'));

        self::assertSame(['error', 'No reviewer runs in this round right now, so nothing was stopped.'], $this->stopsOn($ledger)->requestStopOf('round-1', 'round-1-security'));
    }

    public function testAStopForAReviewerWhenNoReviewerOfTheRoundRunsGivesTheNoReviewerRunsLineAndRecordsNothing(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $ledger->startReviewer('round-1', 'security');
        $ledger->answer('round-1', 'security', 0);

        self::assertSame(['error', 'No reviewer runs in this round right now, so nothing was stopped.'], $this->stopsOn($ledger)->requestStopOf('round-1', 'round-1-security'));
        self::assertCount(0, iterator_to_array($ledger->readOfKind(LedgerEventKind::ReviewerStopRequested), false));
    }

    public function testAStopForAReviewerThatIsNotRunningWhileAnotherRunsHasNoReviewerToStop(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $ledger->startReviewer('round-1', 'security');
        $ledger->answer('round-1', 'security', 0);
        $ledger->startReviewer('round-1', 'style');

        self::assertNull($this->stopsOn($ledger)->requestStopOf('round-1', 'round-1-security'));
        self::assertCount(0, iterator_to_array($ledger->readOfKind(LedgerEventKind::ReviewerStopRequested), false));
    }

    public function testARoundWhereOneStopRequestIsRefusedCountsItAndRecordsTheOther(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $ledger->startReviewer('round-1', 'security');
        $ledger->startReviewer('round-1', 'style');

        self::assertSame(['error', '1 of 2 stop requests could not be recorded in the ledger.'], $this->stopsOn(new ReviewerRefusingLedger($ledger, 'round-1-security'))->requestStopOfRound('round-1'));
        $recorded = iterator_to_array($ledger->readOfKind(LedgerEventKind::ReviewerStopRequested), false);
        self::assertCount(1, $recorded);
        self::assertSame(['round' => 'round-1', 'reviewer' => 'round-1-style'], json_decode($recorded[0]->event->payload, true));
    }

    public function testARoundWhoseAppendsAreRefusedGivesAnErrorLineThatCountsTheRefusedRequests(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $ledger->startReviewer('round-1', 'security');
        $ledger->startReviewer('round-1', 'style');
        $failing = new AppendRefusingLedger($ledger);

        self::assertSame(['error', '2 of 2 stop requests could not be recorded in the ledger.'], $this->stopsOn($failing)->requestStopOfRound('round-1'));
    }

    public function testARoundWithAReviewerWhoseStartedRowDoesNotDeserializeHasNothingToStop(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $ledger->append(new LedgerEvent(LedgerEventKind::ReviewerStarted, '{"round":"round-1","reviewer":"round-1-security","role":"security","tier":"mighty"}'));

        self::assertSame(['error', 'No reviewer runs in this round right now, so nothing was stopped.'], $this->stopsOn($ledger)->requestStopOfRound('round-1'));
    }

    public function testAnUnknownRoundHasNoRoundToStop(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);

        self::assertNull($this->stopsOn($ledger)->requestStopOfRound('round-9'));
    }

    public function testAReviewerInARoundThatEndedWithoutAJudgesCallCannotBeAskedToStop(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $ledger->startReviewer('round-1', 'security');

        self::assertNull($this->endedStopsOn($ledger)->requestStopOf('round-1', 'round-1-security'));
        self::assertCount(0, iterator_to_array($ledger->readOfKind(LedgerEventKind::ReviewerStopRequested), false));
    }

    public function testARoundThatEndedWithoutAJudgesCallHasNoRoundToStop(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $ledger->startReviewer('round-1', 'security');

        self::assertNull($this->endedStopsOn($ledger)->requestStopOfRound('round-1'));
        self::assertCount(0, iterator_to_array($ledger->readOfKind(LedgerEventKind::ReviewerStopRequested), false));
    }

    public function testTwoStopsFromTwoUnnumberedRoundPagesOfOneReviewShareTheLock(): void
    {
        $ledger = new DashboardLedger();

        foreach (['first', 'second'] as $round) {
            $ledger->append(new LedgerEvent(LedgerEventKind::ReviewStarted, json_encode(['round' => $round, 'project' => 'alpha', 'reviewers' => 2, 'change' => 'feat/thing'], JSON_THROW_ON_ERROR)));
            $ledger->startReviewer($round, 'security');
        }

        $locks = new LockFactory(new InMemoryStore());
        // Kept in a variable, because the lock lives on its request and would be freed with it.
        $holding = $this->stopsOn($ledger, $locks);

        self::assertSame('success', $holding->requestStopOf('first', 'first-security')[0] ?? null);
        self::assertSame(['error', 'Another press for this review is still running, so nothing was stopped.'], $this->stopsOn($ledger, $locks)->requestStopOf('second', 'second-security'));
    }

    private function stopsOn(Ledger $ledger, LockFactory $locks = new LockFactory(new InMemoryStore())): ReviewerStops
    {
        return $this->stopsWith($ledger, $locks, new FixedRoundEndings());
    }

    private function endedStopsOn(Ledger $ledger): ReviewerStops
    {
        return $this->stopsWith($ledger, new LockFactory(new InMemoryStore()), new FixedRoundEndings(new RoundEnding(RoundEnds::NoOutcomeInTime, 'No outcome in time.')));
    }

    private function stopsWith(Ledger $ledger, LockFactory $locks, RoundEndings $endings): ReviewerStops
    {
        return new ReviewerStops(new LedgerReviewPages(new MockClock(), $ledger, new RoundPayloads(new LedgerPayloads(StoredSerializerFactory::create())), new RoundLedgerReading($ledger, new LedgerPayloads(StoredSerializerFactory::create()), new ConfiguredPrices([])), new ChangeDecision(), new FailureInWords(), $endings, new ReplayMarks(new ReplayMarkBus())), $ledger, new RoundLock($locks, new RequestStack([new Request()])));
    }
}
