<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewOutcome;
use Intakt\Core\Dashboard\Domain\ReviewRow;
use Intakt\Core\Dashboard\Domain\ReviewSummary;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Review\Contract\QuorumPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewRow::class)]
#[UsesClass(ReviewSummary::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(ReviewOutcome::class)]
#[UsesClass(RoundStart::class)]
#[UsesClass(RoundPlace::class)]
final class ReviewRowTest extends TestCase
{
    private const string STARTED = '2026-01-01T00:00:00+00:00';

    public function testARowIsTitledByItsChangeAndRoundNumber(): void
    {
        self::assertSame('sample-change, round 2', $this->row(new RoundPlace('sample-change', 2), self::STARTED)->title());
    }

    public function testARoundStartedBeforeTheLedgerNamedItsChangeIsTitledAsAnUnnamedChange(): void
    {
        self::assertSame('Unnamed change, round 2', $this->row(new RoundPlace('', 2), self::STARTED)->title());
    }

    public function testARoundWithoutARecordedNumberSaysSoInItsTitle(): void
    {
        self::assertSame('sample-change, round not numbered', $this->row(new RoundPlace('sample-change', null), self::STARTED)->title());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function elapsed(): iterable
    {
        yield 'the same moment' => [self::STARTED, 'just now'];
        yield 'one second short of a minute' => ['2026-01-01T00:00:59+00:00', 'just now'];
        yield 'exactly a minute' => ['2026-01-01T00:01:00+00:00', '1 min ago'];
        yield 'one second short of an hour' => ['2026-01-01T00:59:59+00:00', '59 min ago'];
        yield 'exactly an hour' => ['2026-01-01T01:00:00+00:00', '1 h ago'];
        yield 'two and a half hours' => ['2026-01-01T02:30:00+00:00', '2 h ago'];
        yield 'one second short of a day' => ['2026-01-01T23:59:59+00:00', '23 h ago'];
        yield 'exactly a day' => ['2026-01-02T00:00:00+00:00', '1 d ago'];
        yield 'forty days' => ['2026-02-10T00:00:00+00:00', '40 d ago'];
        yield 'a clock behind the start' => ['2025-12-31T23:00:00+00:00', 'just now'];
    }

    #[DataProvider('elapsed')]
    public function testARowSaysHowLongAgoItsRoundStartedInWholeUnits(string $now, string $ago): void
    {
        self::assertSame($ago, $this->row(new RoundPlace('sample-change', 1), $now)->startedAgo());
    }

    private function row(RoundPlace $place, string $now): ReviewRow
    {
        $round = new ReviewedRound(new RoundStart('r1', 'demo', 2), $place, $this->moment(self::STARTED), QuorumPolicy::All);

        return new ReviewRow(new ReviewSummary($round), $this->moment($now));
    }

    private function moment(string $moment): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat(DATE_ATOM, $moment, new DateTimeZone('UTC'));
        self::assertInstanceOf(DateTimeImmutable::class, $parsed);

        return $parsed;
    }
}
