<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watchdog\Contract;

use Intakt\Core\Watchdog\Contract\OwnerKeys;
use Intakt\Core\Watchdog\Contract\WatchedMain;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WatchedMain::class)]
#[UsesClass(OwnerKeys::class)]
final class WatchedMainTest extends TestCase
{
    public function testFullCommitNamesOfBothObjectFormatsAreAccepted(): void
    {
        $main = new WatchedMain(str_repeat('a', 40), [str_repeat('b', 64)], OwnerKeys::of([]));

        self::assertSame(str_repeat('a', 40), $main->checkedUpTo);
    }

    public function testAnAbbreviatedCheckedHeadIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WatchedMain('abc123', [], OwnerKeys::of([]));
    }

    public function testARecordedMergeThatLooksLikeAnOptionIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WatchedMain(null, ['--all'], OwnerKeys::of([]));
    }
}
