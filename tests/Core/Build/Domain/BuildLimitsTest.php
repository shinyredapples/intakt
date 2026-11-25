<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\Domain;

use Intakt\Core\Build\Domain\BuildLimits;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(BuildLimits::class)]
final class BuildLimitsTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, int, int}>
     */
    public static function limitsWithANonPositiveValue(): iterable
    {
        yield 'allowance' => [0, 600, 4_000, 10_000];
        yield 'idle limit' => [1_000, 0, 4_000, 10_000];
        yield 'ceiling' => [1_000, 600, -1, 10_000];
        yield 'per-task total' => [1_000, 600, 4_000, 0];
    }

    #[DataProvider('limitsWithANonPositiveValue')]
    public function testLimitsWithAValueBelowOneAreRefused(int $allowance, int $idleSeconds, int $ceiling, int $taskTotal): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BuildLimits($allowance, $idleSeconds, $ceiling, $taskTotal);
    }

    public function testLimitsWithACeilingBelowTheAllowanceAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BuildLimits(1_000, 600, 999, 10_000);
    }

    public function testLimitsOfOneEachAndACeilingEqualToTheAllowanceAreAccepted(): void
    {
        $limits = new BuildLimits(1, 1, 1, 1);

        self::assertSame(1, $limits->ceilingTokens);
    }
}
