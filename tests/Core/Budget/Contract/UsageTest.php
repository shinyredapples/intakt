<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Contract;

use Intakt\Core\Budget\Contract\Usage;
use Intakt\Core\Budget\Contract\UsedPercentOutOfRange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Usage::class)]
#[CoversClass(UsedPercentOutOfRange::class)]
final class UsageTest extends TestCase
{
    /**
     * @return iterable<string, array{float}>
     */
    public static function unusablePercents(): iterable
    {
        yield 'negative' => [-0.5];
        yield 'not a number' => [NAN];
        yield 'infinite' => [INF];
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function usablePercents(): iterable
    {
        yield 'unused' => [0.0];
        yield 'at the limit' => [100.0];
        yield 'past the limit' => [104.0];
    }

    #[DataProvider('unusablePercents')]
    public function testAUsedPercentThatIsNegativeOrNotANumberIsRefused(float $usedPercent): void
    {
        $this->expectException(UsedPercentOutOfRange::class);

        new Usage($usedPercent, null);
    }

    #[DataProvider('usablePercents')]
    public function testAUsedPercentFromZeroUpIsKeptAsReported(float $usedPercent): void
    {
        $usage = new Usage($usedPercent, null);

        self::assertSame($usedPercent, $usage->usedPercent);
    }
}
