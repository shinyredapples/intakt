<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CountedMerges::class)]
#[UsesClass(DayRange::class)]
#[UsesClass(Usage::class)]
#[UsesClass(TokenUsage::class)]
#[UsesClass(UsageAccount::class)]
#[UsesClass(UsageLane::class)]
#[UsesClass(UsageVendor::class)]
#[UsesClass(PullRequest::class)]
final class CountedMergesTest extends TestCase
{
    private const string USAGE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string CHANGED_USAGE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testOnlyTheMergesOfTheUsageDaysAreCountedInDayOrder(): void
    {
        $counted = new CountedMerges()->perDay('', self::USAGE, $this->usage(), $this->merged('2026-10-28T08:00:00Z', '2026-10-26T09:00:00Z', '2026-10-28T23:59:59Z', '2026-10-25T23:59:59Z', '2026-10-29T00:00:00Z', null));

        self::assertSame(['2026-10-26' => 1, '2026-10-28' => 2], $counted);
    }

    public function testTheCountsKeptForTheSameUsageFileStayWhenAnotherMergeArrives(): void
    {
        $page = "text\n".new CountedMerges()->line(self::USAGE, ['2026-10-27' => 1])."\nmore\n";

        $counted = new CountedMerges()->perDay($page, self::USAGE, $this->usage(), $this->merged('2026-10-27T09:00:00Z', '2026-10-27T16:00:00Z'));

        self::assertSame(['2026-10-27' => 1], $counted);
    }

    public function testAChangedUsageFileCountsTheMergesAgain(): void
    {
        $page = new CountedMerges()->line(self::USAGE, ['2026-10-27' => 1]);

        $counted = new CountedMerges()->perDay($page, self::CHANGED_USAGE, $this->usage(), $this->merged('2026-10-27T09:00:00Z', '2026-10-27T16:00:00Z'));

        self::assertSame(['2026-10-27' => 2], $counted);
    }

    public function testCountsWithoutAnyMergeAreKeptAsNone(): void
    {
        $page = new CountedMerges()->line(self::USAGE, []);

        self::assertSame([], new CountedMerges()->perDay($page, self::USAGE, $this->usage(), $this->merged('2026-10-27T09:00:00Z')));
    }

    public function testAKeptLineThatDoesNotReadAsCountsIsCountedAgain(): void
    {
        $page = '<!-- merges counted for usage sha256:'.self::USAGE.': 2026-10-27=one -->';

        self::assertSame(['2026-10-27' => 1], new CountedMerges()->perDay($page, self::USAGE, $this->usage(), $this->merged('2026-10-27T09:00:00Z')));
    }

    public function testTheLineNamesTheUsageFileAndEachDaysCount(): void
    {
        self::assertSame('<!-- merges counted for usage sha256:'.self::USAGE.': 2026-10-26=2 2026-10-27=1 -->', new CountedMerges()->line(self::USAGE, ['2026-10-26' => 2, '2026-10-27' => 1]));
    }

    private function usage(): Usage
    {
        return new Usage(array_map(static fn (string $day): TokenUsage => new TokenUsage(new DateTimeImmutable($day), UsageVendor::Claude, UsageAccount::Claude1, UsageLane::Build, 1), ['2026-10-26T00:00:00Z', '2026-10-28T00:00:00Z']));
    }

    /**
     * @return list<PullRequest>
     */
    private function merged(?string ...$merged): array
    {
        return array_values(array_map(static fn (?string $at): PullRequest => new PullRequest(null === $at ? null : new DateTimeImmutable($at)), $merged));
    }
}
