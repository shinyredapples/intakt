<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\Domain;

use Intakt\Core\Build\Domain\BuildLimits;
use Intakt\Core\Build\Domain\BuildLimitsRefused;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(BuildLimits::class)]
#[CoversClass(BuildLimitsRefused::class)]
final class BuildLimitsTest extends TestCase
{
    /**
     * @return iterable<string, array{list<int>}>
     */
    public static function limitsWithANonPositiveValue(): iterable
    {
        yield 'allowance' => [[0, 600, 4_000, 10_000]];
        yield 'idle limit' => [[1_000, 0, 4_000, 10_000]];
        yield 'ceiling' => [[1_000, 600, -1, 10_000]];
        yield 'per-task total' => [[1_000, 600, 4_000, 0]];
    }

    /**
     * @param list<int> $limits
     */
    #[DataProvider('limitsWithANonPositiveValue')]
    public function testLimitsWithAValueBelowOneAreRefused(array $limits): void
    {
        $this->expectException(BuildLimitsRefused::class);

        new BuildLimits(...$limits);
    }

    public function testLimitsWithACeilingBelowTheAllowanceAreRefused(): void
    {
        $this->expectException(BuildLimitsRefused::class);

        new BuildLimits(1_000, 600, 999, 10_000);
    }

    public function testLimitsOfOneEachAndACeilingEqualToTheAllowanceAreAccepted(): void
    {
        $limits = new BuildLimits(1, 1, 1, 1);

        self::assertSame(1, $limits->ceilingTokens);
    }
}
