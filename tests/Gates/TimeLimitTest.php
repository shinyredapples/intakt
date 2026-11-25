<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\TimeLimit;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TimeLimit::class)]
final class TimeLimitTest extends TestCase
{
    /**
     * @return iterable<string, array{float}>
     */
    public static function notFiniteInNanoseconds(): iterable
    {
        yield 'PHP_FLOAT_MAX' => [PHP_FLOAT_MAX];
        yield 'infinity' => [INF];
        yield 'negative infinity' => [-INF];
        yield 'not a number' => [NAN];
    }

    #[DataProvider('notFiniteInNanoseconds')]
    public function testALimitThatIsNotFiniteInNanosecondsIsRefused(float $seconds): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TimeLimit($seconds);
    }

    public function testTheLargestLimitWhoseNanosecondsAreFiniteIsAccepted(): void
    {
        $seconds = PHP_FLOAT_MAX / 1_000_000_000;

        self::assertTrue(is_finite(new TimeLimit($seconds)->nanoseconds()));
    }

    public function testAFractionalLimitConvertsToTheMatchingNanoseconds(): void
    {
        self::assertSame(250_000_000.0, new TimeLimit(0.25)->nanoseconds());
    }

    public function testAFractionalLimitKeepsItsFractionInItsLabel(): void
    {
        self::assertSame('0.5 seconds', new TimeLimit(0.5)->label());
    }

    public function testAWholeLimitIsLabeledWithoutAFraction(): void
    {
        self::assertSame('3 seconds', new TimeLimit(3)->label());
    }
}
