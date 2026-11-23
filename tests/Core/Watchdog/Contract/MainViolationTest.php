<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watchdog\Contract;

use Intakt\Core\Watchdog\Contract\CommitName;
use Intakt\Core\Watchdog\Contract\CommitNameNotFull;
use Intakt\Core\Watchdog\Contract\MainViolation;
use Intakt\Core\Watchdog\Contract\Violation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MainViolation::class)]
#[UsesClass(CommitName::class)]
final class MainViolationTest extends TestCase
{
    public function testAViolationKeepsTheFullNameOfItsCommit(): void
    {
        $commit = str_repeat('e', 64);

        self::assertSame($commit, new MainViolation($commit, Violation::Unsigned)->commit);
    }

    public function testAViolationRefusesAnAbbreviatedCommit(): void
    {
        $this->expectException(CommitNameNotFull::class);

        new MainViolation('eeeeeee', Violation::Unsigned);
    }
}
