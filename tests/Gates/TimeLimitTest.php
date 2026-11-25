<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ProcessResult;
use Intakt\Gates\TimeLimit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TimeLimit::class)]
#[UsesClass(ProcessResult::class)]
final class TimeLimitTest extends TestCase
{
    public function testAFractionOfASecondIsAllowed(): void
    {
        self::assertTrue(TimeLimit::allows(0.2));
    }

    #[DataProvider('refusedLimits')]
    public function testALimitThatIsNotAPositiveFiniteNumberIsNotAllowed(float $seconds): void
    {
        self::assertFalse(TimeLimit::allows($seconds));
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function refusedLimits(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-0.5];
        yield 'infinite' => [INF];
        yield 'negative infinite' => [-INF];
        yield 'not a number' => [NAN];
        yield 'infinite in nanoseconds' => [PHP_FLOAT_MAX];
    }

    public function testTheLargestFloatIsNotAFiniteNumberOfNanoseconds(): void
    {
        self::assertFalse(TimeLimit::isFinite(PHP_FLOAT_MAX));
    }

    public function testALargeLimitWithinTheFloatRangeOfNanosecondsIsFinite(): void
    {
        self::assertTrue(TimeLimit::isFinite(10_000_000_000.0));
    }

    public function testTheLargestFloatCannotRunWithoutNamingTheValue(): void
    {
        $refusal = TimeLimit::refusal('sh', PHP_FLOAT_MAX);

        self::assertSame(127, $refusal->exitCode);
        self::assertSame('cannot run sh: its time limit is not a finite number of seconds', $refusal->errors);
    }

    public function testALimitOfZeroTimesOutNamingTheLimit(): void
    {
        $refusal = TimeLimit::refusal('sh', 0.0);

        self::assertSame(124, $refusal->exitCode);
        self::assertSame('sh timed out after 0 seconds', $refusal->errors);
    }

    public function testALimitThatIsNotANumberCannotRunWithoutNamingTheValue(): void
    {
        $refusal = TimeLimit::refusal('sh', NAN);

        self::assertSame(127, $refusal->exitCode);
        self::assertSame('cannot run sh: its time limit is not a finite number of seconds', $refusal->errors);
    }
}
