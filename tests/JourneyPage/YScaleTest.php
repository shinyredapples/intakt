<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use Iterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(YScale::class)]
final class YScaleTest extends TestCase
{
    /**
     * @param list<string> $labels
     */
    #[DataProvider('scales')]
    public function testTheTopRoundsUpAndTheFourStepsGetReadableLabels(int $largest, float $top, array $labels): void
    {
        $scale = new YScale($largest, 280, 264);

        self::assertSame([$top, $labels], [$scale->top, array_map($scale->label(...), $scale->ticks())]);
    }

    /**
     * @return Iterator<string, array{int, float, list<string>}>
     */
    public static function scales(): Iterator
    {
        yield 'tokens a day in the tens of millions' => [97_000_000, 100_000_000.0, ['0', '25M', '50M', '75M', '100M']];
        yield 'a top that is already round' => [5_000_000, 5_000_000.0, ['0', '1.25M', '2.50M', '3.75M', '5.00M']];
        yield 'one more than a round top' => [5_000_001, 10_000_000.0, ['0', '2.5M', '5.0M', '7.5M', '10.0M']];
        yield 'thousands' => [3_100, 4_000.0, ['0', '1k', '2k', '3k', '4k']];
        yield 'issue counts' => [173, 200.0, ['0', '50', '100', '150', '200']];
        yield 'nothing at all' => [0, 4.0, ['0', '1', '2', '3', '4']];
    }

    public function testTheTopSitsAtThePlotTopAndZeroOnTheBaseline(): void
    {
        $scale = new YScale(200, 280, 264);

        self::assertSame([280.0, 16.0], [$scale->y(0), $scale->y(200)]);
    }
}
