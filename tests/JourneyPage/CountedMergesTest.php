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

    public function testOnlyTheMergesFromTheFirstToTheLastUsageDayAreCountedInDayOrder(): void
    {
        $counted = new CountedMerges()->countedFor($this->usage(), [
            new PullRequest(new DateTimeImmutable('2026-10-28T08:00:00Z')),
            new PullRequest(new DateTimeImmutable('2026-10-26T09:00:00Z')),
            new PullRequest(new DateTimeImmutable('2026-10-28T23:59:59Z')),
            new PullRequest(new DateTimeImmutable('2026-10-25T23:59:59Z')),
            new PullRequest(new DateTimeImmutable('2026-10-29T00:00:00Z')),
            new PullRequest(null),
        ]);

        self::assertSame(['2026-10-26' => 1, '2026-10-28' => 2], $counted);
    }

    public function testTheCountsKeptForTheSameUsageFileAreReadBack(): void
    {
        $page = "text\n".new CountedMerges()->line(self::USAGE, ['2026-10-26' => 2, '2026-10-27' => 1])."\nmore\n";

        self::assertSame(['2026-10-26' => 2, '2026-10-27' => 1], new CountedMerges()->keptOn($page, self::USAGE));
    }

    public function testCountsKeptWithoutAnyMergeAreReadBackAsNone(): void
    {
        self::assertSame([], new CountedMerges()->keptOn(new CountedMerges()->line(self::USAGE, []), self::USAGE));
    }

    public function testCountsKeptForAnotherUsageFileAreNotUsed(): void
    {
        self::assertNull(new CountedMerges()->keptOn(new CountedMerges()->line(self::USAGE, ['2026-10-27' => 1]), self::CHANGED_USAGE));
    }

    public function testAKeptLineThatDoesNotReadAsCountsIsNotUsed(): void
    {
        self::assertNull(new CountedMerges()->keptOn('<!-- merges counted for usage sha256:'.self::USAGE.': 2026-10-27=one -->', self::USAGE));
    }

    public function testAPageWithoutKeptCountsHasNone(): void
    {
        self::assertNull(new CountedMerges()->keptOn("text\n", self::USAGE));
    }

    public function testTheLineNamesTheUsageFileAndEachDaysCount(): void
    {
        self::assertSame('<!-- merges counted for usage sha256:'.self::USAGE.': 2026-10-26=2 2026-10-27=1 -->', new CountedMerges()->line(self::USAGE, ['2026-10-26' => 2, '2026-10-27' => 1]));
    }

    private function usage(): Usage
    {
        return new Usage([
            new TokenUsage(new DateTimeImmutable('2026-10-26T00:00:00Z'), UsageVendor::Claude, UsageAccount::Claude1, UsageLane::Build, 1),
            new TokenUsage(new DateTimeImmutable('2026-10-28T00:00:00Z'), UsageVendor::Claude, UsageAccount::Claude1, UsageLane::Build, 1),
        ]);
    }
}
