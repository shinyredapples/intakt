<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watchdog\Contract;

use Intakt\Core\Watchdog\Contract\CommitName;
use Intakt\Core\Watchdog\Contract\CommitNameNotFull;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CommitName::class)]
#[UsesClass(CommitNameNotFull::class)]
final class CommitNameTest extends TestCase
{
    public function testAFullCommitNameIsKept(): void
    {
        self::assertSame(str_repeat('a', 40), new CommitName(str_repeat('a', 40))->name);
    }

    public function testASha256CommitNameIsKept(): void
    {
        self::assertSame(str_repeat('b', 64), new CommitName(str_repeat('b', 64))->name);
    }

    public function testACommitNameThatIsNotAFullHashIsRefusedWhenConstructed(): void
    {
        $this->expectException(CommitNameNotFull::class);

        new CommitName('abc123');
    }
}
