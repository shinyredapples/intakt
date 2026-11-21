<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Infrastructure\LedgerFailingHeads;
use Intakt\Core\Dashboard\Infrastructure\WatcherLines;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WatcherLines::class)]
final class WatcherLinesTest extends TestCase
{
    public function testTheFailingHeadsSplitCoversEveryWatcherLineOnce(): void
    {
        $split = [...LedgerFailingHeads::FAILED, ...LedgerFailingHeads::SETTLED];

        self::assertCount(count(WatcherLines::ALL), $split);

        foreach (WatcherLines::ALL as $kind) {
            self::assertContains($kind, $split, $kind->name);
        }
    }
}
