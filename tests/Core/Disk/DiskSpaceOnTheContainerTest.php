<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Disk;

use Intakt\Core\Disk\Contract\DiskFloor;
use Intakt\Core\Disk\Contract\DiskHold;
use Intakt\Core\Disk\Contract\DiskServices;
use Intakt\Core\Disk\Contract\DiskSpace;
use Intakt\Core\Disk\Infrastructure\DiskConfiguration;
use Intakt\Core\Disk\Infrastructure\FlooredDiskSpace;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(FlooredDiskSpace::class)]
#[UsesClass(DiskHold::class)]
#[UsesClass(DiskConfiguration::class)]
#[UsesClass(ControlledFreeSpaceReader::class)]
final class DiskSpaceOnTheContainerTest extends KernelTestCase
{
    private const int BYTES = DiskConfiguration::DEFAULT_MIN_FREE_BYTES;

    private const int INODES = DiskConfiguration::DEFAULT_MIN_FREE_INODES;

    public function testTheContainerBindsTheDefaultFloors(): void
    {
        $container = self::getContainer();

        self::assertSame([self::BYTES, self::INODES], [$container->getParameter(DiskServices::MIN_FREE_BYTES), $container->getParameter(DiskServices::MIN_FREE_INODES)]);
    }

    public function testTheWiredContainerHoldsAtEachFloorAndResumesAboveIt(): void
    {
        $reader = self::getContainer()->get(ControlledFreeSpaceReader::class);
        $disk = self::getContainer()->get('test.intakt.disk_space');
        self::assertInstanceOf(DiskSpace::class, $disk);

        $held = [];
        foreach ([[self::BYTES - 1, self::INODES], [self::BYTES, self::INODES - 1], [self::BYTES, self::INODES], [self::BYTES, null]] as [$bytes, $inodes]) {
            $reader->report($bytes, $inodes);
            $held[] = $disk->holdAt('/data/mirrors');
        }

        self::assertEquals([
            new DiskHold(DiskFloor::Bytes, '/data/mirrors', self::BYTES - 1, self::BYTES),
            new DiskHold(DiskFloor::Inodes, '/data/mirrors', self::INODES - 1, self::INODES),
            null,
            null,
        ], $held);
    }
}
