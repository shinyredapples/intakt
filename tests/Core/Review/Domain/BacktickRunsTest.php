<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Domain;

use Intakt\Core\Review\Domain\BacktickRuns;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BacktickRuns::class)]
final class BacktickRunsTest extends TestCase
{
    public function testASpanEndsAfterTheNextRunOfTheSameLength(): void
    {
        self::assertSame(9, new BacktickRuns('A ``b`c`` d')->spanEnd(2, 4));
    }

    public function testARunWithoutACloserOfItsLengthEndsWhereItStarted(): void
    {
        self::assertSame(5, new BacktickRuns('A ``` b `` c')->spanEnd(3, 5));
    }

    public function testARunBeforeTheCursorIsNoCloser(): void
    {
        $runs = new BacktickRuns('`a` `b');

        self::assertSame(3, $runs->spanEnd(1, 1));
        self::assertSame(5, $runs->spanEnd(1, 5));
    }

    public function testThePartOfALongerRunIsNoCloser(): void
    {
        self::assertSame(3, new BacktickRuns('A ` b ```')->spanEnd(1, 3));
    }
}
