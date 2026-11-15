<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UsageReader::class)]
#[UsesClass(Usage::class)]
#[UsesClass(TokenUsage::class)]
#[UsesClass(UsageAccount::class)]
#[UsesClass(UsageLane::class)]
#[UsesClass(UsageVendor::class)]
final class JourneyDailyClaimsTest extends TestCase
{
    public function testEachDayTheHandWrittenTextGivesMeasuredTokensForMatchesTheUsageFile(): void
    {
        $root = dirname(__DIR__, 2);
        $usage = new UsageReader()->usageIn($root.'/docs/journey/usage.jsonl');
        $page = (string) file_get_contents($root.'/docs/journey.md');

        preg_match_all('/\b(\d{2}-\d{2}): [^.]*?\b(\d+)M measured tokens/', $page, $claims, PREG_SET_ORDER);

        self::assertNotSame([], $claims);
        foreach ($claims as [$claim, $day, $millions]) {
            $tokens = 0;
            foreach ($usage->rows as $row) {
                $tokens += $row->day->format('m-d') === $day ? $row->tokens : 0;
            }
            self::assertSame((int) round($tokens / 1_000_000), (int) $millions, $claim);
        }
    }
}
