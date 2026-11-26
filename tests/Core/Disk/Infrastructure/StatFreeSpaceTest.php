<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Disk\Infrastructure;

use Intakt\Core\Disk\Infrastructure\FreeSpaceUnreadable;
use Intakt\Core\Disk\Infrastructure\StatFreeSpace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StatFreeSpace::class)]
#[CoversClass(FreeSpaceUnreadable::class)]
final class StatFreeSpaceTest extends TestCase
{
    public function testItReportsFreeBytesOfAnExistingFolder(): void
    {
        self::assertGreaterThan(0, new StatFreeSpace()->freeBytes(sys_get_temp_dir()));
    }

    public function testItReportsFreeInodesOfAnExistingFolder(): void
    {
        self::assertGreaterThanOrEqual(0, new StatFreeSpace()->freeInodes(sys_get_temp_dir()));
    }

    public function testAFolderThatDoesNotExistIsAnExplicitFailureForBytes(): void
    {
        $this->expectException(FreeSpaceUnreadable::class);

        new StatFreeSpace()->freeBytes(__DIR__.'/missing');
    }

    public function testAFolderThatDoesNotExistIsAnExplicitFailureForInodes(): void
    {
        $this->expectException(FreeSpaceUnreadable::class);

        new StatFreeSpace()->freeInodes(__DIR__.'/missing');
    }
}
