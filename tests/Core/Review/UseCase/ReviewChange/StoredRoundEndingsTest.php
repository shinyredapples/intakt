<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ReviewChange;

use DateTimeImmutable;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Core\Review\Contract\JudgingFailed;
use Intakt\Core\Review\Contract\RoundEnding;
use Intakt\Core\Review\Contract\RoundEnds;
use Intakt\Core\Review\UseCase\JudgeRound\FailedJudgings;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Core\Review\UseCase\ReviewChange\RoundEndReasons;
use Intakt\Core\Review\UseCase\ReviewChange\StoredRoundEndings;
use Intakt\Core\Review\UseCase\ReviewChange\UpperWait;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(StoredRoundEndings::class)]
#[UsesClass(RoundEnding::class)]
#[UsesClass(RoundEndReasons::class)]
#[UsesClass(UpperWait::class)]
#[UsesClass(FailedJudgings::class)]
#[UsesClass(JudgeRound::class)]
#[UsesClass(JudgingFailed::class)]
#[UsesClass(LedgerPayloads::class)]
final class StoredRoundEndingsTest extends TestCase
{
    public function testARoundWithinItsUpperWaitHasNotEnded(): void
    {
        $endings = $this->endingsAt('2026-10-28T12:00:09+00:00');

        self::assertNull($endings->ofUnjudged('round-1', 2, new DateTimeImmutable('2026-10-28T12:00:00+00:00')));
    }

    public function testARoundPastItsUpperWaitEndedWithNoOutcomeInTime(): void
    {
        $ending = $this->endingsAt('2026-10-28T12:00:10+00:00')->ofUnjudged('round-1', 2, new DateTimeImmutable('2026-10-28T12:00:00+00:00'));

        self::assertInstanceOf(RoundEnding::class, $ending);
        self::assertSame(RoundEnds::NoOutcomeInTime, $ending->end);
        self::assertStringStartsWith(RoundEndReasons::NO_OUTCOME_IN_TIME.': the round did not end within 10 seconds', $ending->reason);
    }

    public function testARoundWithNoOutcomeInTimeEndedWhenItsUpperWaitRanOut(): void
    {
        $ending = $this->endingsAt('2026-10-28T12:05:00+00:00')->ofUnjudged('round-1', 2, new DateTimeImmutable('2026-10-28T12:00:00+00:00'));

        self::assertEquals(new DateTimeImmutable('2026-10-28T12:00:10+00:00'), $ending?->endedAt);
    }

    public function testAJudgingFailureRecordedInTheLedgerEndedTheRoundWhenItWasRecorded(): void
    {
        $ending = $this->endingsAt('2026-10-28T12:30:00+00:00', recorded: ['round-1'])->ofUnjudged('round-1', 2, new DateTimeImmutable('2026-10-28T11:00:00+00:00'));

        self::assertEquals(new DateTimeImmutable('2026-10-28T12:00:00+00:00'), $ending?->endedAt);
    }

    public function testARoundWhoseJudgingFailureIsRecordedInTheLedgerEndedWithJudgingFailedEvenWithinItsWait(): void
    {
        $ending = $this->endingsAt('2026-10-28T12:00:00+00:00', recorded: ['round-1'])->ofUnjudged('round-1', 2, new DateTimeImmutable('2026-10-28T12:00:00+00:00'));

        self::assertInstanceOf(RoundEnding::class, $ending);
        self::assertSame(RoundEnds::JudgingFailed, $ending->end);
        self::assertSame(RoundEndReasons::JUDGING_FAILED, $ending->reason);
    }

    public function testAJudgingFailureRecordedForAnotherRoundDoesNotEndThisOne(): void
    {
        $endings = $this->endingsAt('2026-10-28T12:00:00+00:00', recorded: ['round-2']);

        self::assertNull($endings->ofUnjudged('round-1', 2, new DateTimeImmutable('2026-10-28T12:00:00+00:00')));
    }

    public function testAJudgingParkedInTheFailureTransportWithoutALedgerRecordEndedWithJudgingFailedEvenPastItsWait(): void
    {
        $ending = $this->endingsAt('2026-10-29T12:00:00+00:00', parked: [new Envelope(new JudgeRound('round-1'))])->ofUnjudged('round-1', 2, new DateTimeImmutable('2026-10-28T12:00:00+00:00'));

        self::assertSame(RoundEnds::JudgingFailed, $ending?->end);
    }

    public function testAJudgingParkedWithoutALedgerRecordHasNoTimeItEnded(): void
    {
        $ending = $this->endingsAt('2026-10-28T12:00:00+00:00', parked: [new Envelope(new JudgeRound('round-1'))])->ofUnjudged('round-1', 2, new DateTimeImmutable('2026-10-28T12:00:00+00:00'));

        self::assertInstanceOf(RoundEnding::class, $ending);
        self::assertNull($ending->endedAt);
    }

    public function testARoundWhoseJudgingFailureIsRecordedReadsTheLedgerOnce(): void
    {
        $ledger = $this->createMock(Ledger::class);
        $ledger->expects(self::once())->method('readOfKind')->with(LedgerEventKind::JudgingFailed)->willReturn($this->judgingFailuresOf(['round-1']));

        $ending = $this->endingsReading($ledger, '2026-10-28T12:00:00+00:00')->ofUnjudged('round-1', 2, new DateTimeImmutable('2026-10-28T12:00:00+00:00'));

        self::assertEquals(new DateTimeImmutable('2026-10-28T12:00:00+00:00'), $ending?->endedAt);
    }

    public function testARoundWhoseJudgingIsOnlyParkedReadsTheLedgerOnce(): void
    {
        $ledger = $this->createMock(Ledger::class);
        $ledger->expects(self::once())->method('readOfKind')->with(LedgerEventKind::JudgingFailed)->willReturn($this->judgingFailuresOf([]));

        $ending = $this->endingsReading($ledger, '2026-10-28T12:00:00+00:00', [new Envelope(new JudgeRound('round-1'))])->ofUnjudged('round-1', 2, new DateTimeImmutable('2026-10-28T12:00:00+00:00'));

        self::assertInstanceOf(RoundEnding::class, $ending);
        self::assertSame(RoundEnds::JudgingFailed, $ending->end);
        self::assertNull($ending->endedAt);
    }

    /**
     * @param list<string>   $recorded
     * @param list<Envelope> $parked
     */
    private function endingsAt(string $now, array $recorded = [], array $parked = []): StoredRoundEndings
    {
        $ledger = self::createStub(Ledger::class);
        $ledger->method('readOfKind')->willReturn($this->judgingFailuresOf($recorded));

        return $this->endingsReading($ledger, $now, $parked);
    }

    /**
     * @param list<string> $rounds
     */
    private function judgingFailuresOf(array $rounds): RecordedEvents
    {
        return new RecordedEvents(array_map(
            static fn (string $round): RecordedEvent => new RecordedEvent(Uuid::v7(), new LedgerEvent(LedgerEventKind::JudgingFailed, json_encode(['round' => $round], JSON_THROW_ON_ERROR)), new DateTimeImmutable('2026-10-28T12:00:00+00:00')),
            $rounds,
        ));
    }

    /**
     * @param list<Envelope> $parked
     */
    private function endingsReading(Ledger $ledger, string $now, array $parked = []): StoredRoundEndings
    {
        $transport = self::createStub(ListableReceiverInterface::class);
        $transport->method('all')->willReturn($parked);

        return new StoredRoundEndings(new FailedJudgings($transport, $ledger, new LedgerPayloads(StoredSerializerFactory::create())), new MockClock($now), new UpperWait(4, 2));
    }
}
