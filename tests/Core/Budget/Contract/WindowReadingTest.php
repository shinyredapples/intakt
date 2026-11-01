<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Contract;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Contract\UsedPercentOutOfRange;
use Intakt\Core\Budget\Contract\WindowReading;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WindowReading::class)]
#[CoversClass(UsedPercentOutOfRange::class)]
#[UsesClass(Account::class)]
final class WindowReadingTest extends TestCase
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

        new WindowReading(new Account(Provider::ClaudeCode, 'main'), UsageWindow::FiveHour, $usedPercent, null);
    }

    #[DataProvider('usablePercents')]
    public function testAUsedPercentFromZeroUpIsKeptAsReported(float $usedPercent): void
    {
        $reading = new WindowReading(new Account(Provider::ClaudeCode, 'main'), UsageWindow::FiveHour, $usedPercent, null);

        self::assertSame($usedPercent, $reading->usedPercent);
    }
}
