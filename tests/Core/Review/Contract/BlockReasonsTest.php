<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\BlockReasons;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BlockReasons::class)]
final class BlockReasonsTest extends TestCase
{
    public function testTheReasonsIterateInTheOrderTheyWereGiven(): void
    {
        self::assertSame(['the replay', 'role-1 reviewer failed'], [...new BlockReasons('the replay', 'role-1 reviewer failed')]);
    }

    public function testAReasonPutFirstComesBeforeTheOthers(): void
    {
        $reasons = new BlockReasons('role-1 reviewer failed')->withFirst('the judging failed');

        self::assertSame(['the judging failed', 'role-1 reviewer failed'], [...$reasons]);
    }

    public function testPuttingAReasonFirstLeavesTheOriginalReasonsAsTheyWere(): void
    {
        $reasons = new BlockReasons('role-1 reviewer failed');

        $reasons->withFirst('the judging failed');

        self::assertSame(['role-1 reviewer failed'], [...$reasons]);
    }

    public function testReasonsSpreadFromAStringKeyedArrayIterateAsAList(): void
    {
        self::assertSame(['a', 'b'], [...new BlockReasons(...['first' => 'a', 'second' => 'b'])]);
    }

    public function testNoReasonsIterateAsNothing(): void
    {
        self::assertSame([], [...new BlockReasons()]);
    }
}
