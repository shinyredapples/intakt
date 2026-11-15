<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ReviewChange;

use DateTimeImmutable;
use Intakt\Core\Review\Contract\RoundEnding;
use Intakt\Core\Review\Contract\RoundEnds;
use Intakt\Core\Review\UseCase\JudgeRound\FailedJudgings;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Core\Review\UseCase\ReviewChange\RoundEndReasons;
use Intakt\Core\Review\UseCase\ReviewChange\StoredRoundEndings;
use Intakt\Core\Review\UseCase\ReviewChange\UpperWait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;

#[CoversClass(StoredRoundEndings::class)]
#[UsesClass(RoundEnding::class)]
#[UsesClass(RoundEndReasons::class)]
#[UsesClass(UpperWait::class)]
#[UsesClass(FailedJudgings::class)]
#[UsesClass(JudgeRound::class)]
final class StoredRoundEndingsTest extends TestCase
{
    public function testARoundWithinItsUpperWaitHasNotEnded(): void
    {
        $endings = $this->endingsAt('2026-10-28T12:00:09+00:00', []);

        self::assertNull($endings->ofUnjudged('round-1', 2, new DateTimeImmutable('2026-10-28T12:00:00+00:00')));
    }

    public function testARoundPastItsUpperWaitEndedWithNoOutcomeInTime(): void
    {
        $ending = $this->endingsAt('2026-10-28T12:00:10+00:00', [])->ofUnjudged('round-1', 2, new DateTimeImmutable('2026-10-28T12:00:00+00:00'));

        self::assertInstanceOf(RoundEnding::class, $ending);
        self::assertSame(RoundEnds::NoOutcomeInTime, $ending->end);
        self::assertStringStartsWith(RoundEndReasons::NO_OUTCOME_IN_TIME.': the round did not end within 10 seconds', $ending->reason);
    }

    public function testARoundWhoseJudgingIsParkedAsFailedEndedWithJudgingFailedEvenWithinItsWait(): void
    {
        $ending = $this->endingsAt('2026-10-28T12:00:00+00:00', [new Envelope(new JudgeRound('round-1'))])->ofUnjudged('round-1', 2, new DateTimeImmutable('2026-10-28T12:00:00+00:00'));

        self::assertInstanceOf(RoundEnding::class, $ending);
        self::assertSame(RoundEnds::JudgingFailed, $ending->end);
        self::assertSame(RoundEndReasons::JUDGING_FAILED, $ending->reason);
    }

    public function testAParkedJudgingOfAnotherRoundDoesNotEndThisOne(): void
    {
        $endings = $this->endingsAt('2026-10-28T12:00:00+00:00', [new Envelope(new JudgeRound('round-2'))]);

        self::assertNull($endings->ofUnjudged('round-1', 2, new DateTimeImmutable('2026-10-28T12:00:00+00:00')));
    }

    /**
     * @param list<Envelope> $parked
     */
    private function endingsAt(string $now, array $parked): StoredRoundEndings
    {
        $transport = self::createStub(ListableReceiverInterface::class);
        $transport->method('all')->willReturn($parked);

        return new StoredRoundEndings(new FailedJudgings($transport), new MockClock($now), new UpperWait(4, 2));
    }
}
