<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Disk\Infrastructure;

use Intakt\Core\Disk\Contract\DiskFloor;
use Intakt\Core\Disk\Contract\DiskHold;
use Intakt\Core\Disk\Contract\FreeSpaceUnreadable;
use Intakt\Core\Disk\Infrastructure\FlooredDiskSpace;
use Intakt\Core\Disk\Infrastructure\FreeSpaceReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

#[CoversClass(FlooredDiskSpace::class)]
#[UsesClass(DiskHold::class)]
#[UsesClass(FreeSpaceUnreadable::class)]
final class FlooredDiskSpaceTest extends TestCase
{
    private const int MIN_BYTES = 1000;

    private const int MIN_INODES = 50;

    public function testAFolderWithFreeBytesBelowTheFloorIsHeldNamingTheBytesFloorAndTheFolder(): void
    {
        $disk = $this->diskReading(self::MIN_BYTES - 1, self::MIN_INODES);

        $hold = $disk->holdAt('/data/mirrors');

        self::assertEquals(new DiskHold(DiskFloor::Bytes, '/data/mirrors', self::MIN_BYTES - 1, self::MIN_BYTES), $hold);
    }

    public function testAFolderWithFreeInodesBelowTheFloorIsHeldNamingTheInodesFloorAndTheFolder(): void
    {
        $disk = $this->diskReading(self::MIN_BYTES, self::MIN_INODES - 1);

        $hold = $disk->holdAt('/data/clones');

        self::assertEquals(new DiskHold(DiskFloor::Inodes, '/data/clones', self::MIN_INODES - 1, self::MIN_INODES), $hold);
    }

    public function testAFolderAtBothFloorsIsNotHeld(): void
    {
        $disk = $this->diskReading(self::MIN_BYTES, self::MIN_INODES);

        self::assertNull($disk->holdAt('/data'));
    }

    public function testAFolderBelowBothFloorsIsHeldAtTheBytesFloor(): void
    {
        $disk = $this->diskReading(0, 0);

        self::assertSame(DiskFloor::Bytes, $disk->holdAt('/data')?->floor);
    }

    public function testAFileSystemWithoutAnInodeCountIsHeldOnlyByItsBytes(): void
    {
        $disk = $this->diskReading(self::MIN_BYTES, null);

        self::assertNull($disk->holdAt('/data'));
    }

    public function testAHoldClearsOnceTheReaderReportsSpaceAboveTheFloorAgain(): void
    {
        $reader = $this->createStub(FreeSpaceReader::class);
        $reader->method('freeBytes')->willReturn(self::MIN_BYTES - 1, self::MIN_BYTES, self::MIN_BYTES, self::MIN_BYTES);
        $reader->method('freeInodes')->willReturn(self::MIN_INODES - 1, self::MIN_INODES);
        $disk = new FlooredDiskSpace($reader, self::MIN_BYTES, self::MIN_INODES);

        $held = [$disk->holdAt('/data')?->floor, $disk->holdAt('/data')?->floor, $disk->holdAt('/data')?->floor];

        self::assertSame([DiskFloor::Bytes, DiskFloor::Inodes, null], $held);
    }

    public function testAReaderThatCannotMeasureTheDiskRaisesFreeSpaceUnreadable(): void
    {
        $reader = $this->createStub(FreeSpaceReader::class);
        $reader->method('freeBytes')->willThrowException(new FreeSpaceUnreadable('/data', 'no such folder'));
        $disk = new FlooredDiskSpace($reader, self::MIN_BYTES, self::MIN_INODES);

        $this->expectExceptionObject(new FreeSpaceUnreadable('/data', 'no such folder'));

        $disk->holdAt('/data');
    }

    public function testAnInodeReadThatTimesOutPropagatesTheProcessTimeoutException(): void
    {
        $reader = $this->createStub(FreeSpaceReader::class);
        $reader->method('freeBytes')->willReturn(self::MIN_BYTES);
        $reader->method('freeInodes')->willThrowException(new ProcessTimedOutException(new Process(['df']), ProcessTimedOutException::TYPE_GENERAL));
        $disk = new FlooredDiskSpace($reader, self::MIN_BYTES, self::MIN_INODES);

        $this->expectException(ProcessTimedOutException::class);

        $disk->holdAt('/data');
    }

    private function diskReading(int $freeBytes, ?int $freeInodes): FlooredDiskSpace
    {
        $reader = $this->createStub(FreeSpaceReader::class);
        $reader->method('freeBytes')->willReturn($freeBytes);
        $reader->method('freeInodes')->willReturn($freeInodes);

        return new FlooredDiskSpace($reader, self::MIN_BYTES, self::MIN_INODES);
    }
}
