<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ledger;

use Intakt\Core\Ledger\Contract\LedgerEventsAreAppendOnly;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LedgerEventsAreAppendOnly::class)]
final class LedgerEventsAreAppendOnlyTest extends TestCase
{
    public function testAnUpdateIsRefusedBecauseTheLedgerOnlyAppends(): void
    {
        self::assertSame('A stored ledger event cannot be updated: the ledger only appends.', LedgerEventsAreAppendOnly::updated()->getMessage());
    }

    public function testADeleteIsRefusedBecauseTheLedgerOnlyAppends(): void
    {
        self::assertSame('A stored ledger event cannot be deleted: the ledger only appends.', LedgerEventsAreAppendOnly::deleted()->getMessage());
    }
}
