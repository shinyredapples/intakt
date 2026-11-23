<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use Intakt\Core\Dashboard\Domain\ChartScale;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChartScale::class)]
final class ChartScaleTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int}>
     */
    public static function largestCounts(): iterable
    {
        yield 'nothing counted' => [0, 2];
        yield 'one' => [1, 2];
        yield 'two' => [2, 2];
        yield 'three' => [3, 4];
        yield 'nine' => [9, 10];
        yield 'ten' => [10, 10];
        yield 'eleven' => [11, 20];
        yield 'forty-five' => [45, 60];
        yield 'a hundred' => [100, 100];
        yield 'a hundred and one' => [101, 200];
    }

    #[DataProvider('largestCounts')]
    public function testTheTopIsTheSmallestEvenRoundCountAtOrAboveTheLargest(int $largest, int $top): void
    {
        self::assertSame($top, ChartScale::topFor($largest));
    }
}
