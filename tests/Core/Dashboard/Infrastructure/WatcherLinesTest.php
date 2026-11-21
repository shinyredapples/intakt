<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Infrastructure\LedgerFailingHeads;
use Intakt\Core\Dashboard\Infrastructure\WatcherLines;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WatcherLines::class)]
final class WatcherLinesTest extends TestCase
{
    public function testTheFailingHeadsSplitCoversEveryWatcherLineOnce(): void
    {
        $split = [...LedgerFailingHeads::FAILED, ...LedgerFailingHeads::SETTLED];
        $names = static fn (array $kinds): array => array_map(static fn (LedgerEventKind $kind): string => $kind->name, $kinds);

        self::assertEqualsCanonicalizing($names(WatcherLines::ALL), $names($split));
        self::assertCount(count(WatcherLines::ALL), $split);
    }
}
