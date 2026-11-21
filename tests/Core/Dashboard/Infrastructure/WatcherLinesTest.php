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
        self::assertEqualsCanonicalizing(self::namesOf(WatcherLines::ALL), self::namesOf($split));
        self::assertCount(count(WatcherLines::ALL), $split);
    }

    /**
     * @param array<LedgerEventKind> $kinds
     *
     * @return list<string>
     */
    private static function namesOf(array $kinds): array
    {
        return array_values(array_map(static fn (LedgerEventKind $kind): string => $kind->name, $kinds));
    }
}
