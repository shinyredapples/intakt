<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Disk\Domain;

use Intakt\Core\Disk\Contract\DiskFloor;
use Intakt\Core\Disk\Contract\DiskHeld;
use Intakt\Core\Disk\Domain\FloorCheck;
use Intakt\Core\Disk\Domain\FloorRefused;
use Intakt\Tests\Core\Disk\FreeSpaceInMemory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FloorCheck::class)]
#[CoversClass(FloorRefused::class)]
#[UsesClass(DiskHeld::class)]
#[UsesClass(DiskFloor::class)]
final class FloorCheckTest extends TestCase
{
    public function testWorkIsHeldBelowTheBytesFloor(): void
    {
        $held = new FloorCheck(new FreeSpaceInMemory(999, 500), '/var/intakt', 1000, 100)->held();

        self::assertEquals(new DiskHeld(DiskFloor::Bytes, '/var/intakt', 999, 1000), $held);
    }

    public function testWorkIsHeldBelowTheInodesFloorThoughBytesArePlenty(): void
    {
        $held = new FloorCheck(new FreeSpaceInMemory(10 ** 12, 99), '/var/intakt', 1000, 100)->held();

        self::assertEquals(new DiskHeld(DiskFloor::Inodes, '/var/intakt', 99, 100), $held);
    }

    public function testBytesAreNamedFirstWhenBothFloorsAreCrossed(): void
    {
        $held = new FloorCheck(new FreeSpaceInMemory(1, 1), '/var/intakt', 1000, 100)->held();

        self::assertSame(DiskFloor::Bytes, $held?->floor);
    }

    public function testWorkIsNotHeldExactlyAtBothFloors(): void
    {
        self::assertNull(new FloorCheck(new FreeSpaceInMemory(1000, 100), '/var/intakt', 1000, 100)->held());
    }

    public function testWorkResumesOnceSpaceIsBackAboveBothFloors(): void
    {
        $space = new FreeSpaceInMemory(10, 10);
        $check = new FloorCheck($space, '/var/intakt', 1000, 100);
        self::assertInstanceOf(DiskHeld::class, $check->held());

        $space->leave(5000, 500);

        self::assertNull($check->held());
    }

    public function testTheFolderTheRunnerWritesToIsTheOneRead(): void
    {
        $space = new FreeSpaceInMemory(5000, 500);

        new FloorCheck($space, '/var/intakt', 1000, 100)->held();

        self::assertSame(['/var/intakt', '/var/intakt'], $space->asked);
    }

    #[DataProvider('floorsBelowOne')]
    public function testAFloorBelowOneIsRefusedAtBoot(int $bytes, int $inodes): void
    {
        $this->expectException(FloorRefused::class);

        new FloorCheck(new FreeSpaceInMemory(1, 1), '/var/intakt', $bytes, $inodes);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function floorsBelowOne(): iterable
    {
        yield 'zero bytes' => [0, 100];
        yield 'negative bytes' => [-1, 100];
        yield 'zero inodes' => [1000, 0];
        yield 'negative inodes' => [1000, -5];
    }
}
