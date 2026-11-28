<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ShowLanes;

use Intakt\Core\Dashboard\UseCase\ShowLanes\HeldForSpace;
use Intakt\Core\Disk\Contract\DiskFloor;
use Intakt\Core\Disk\Contract\DiskHold;
use Intakt\Core\Disk\Contract\DiskSpace;
use Intakt\Core\Disk\Contract\FreeSpaceUnreadable;
use Intakt\Core\Disk\Contract\NearestFolder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

#[CoversClass(HeldForSpace::class)]
#[UsesClass(DiskHold::class)]
#[UsesClass(NearestFolder::class)]
#[UsesClass(FreeSpaceUnreadable::class)]
final class HeldForSpaceTest extends TestCase
{
    private const string MIRRORS = '/data/mirrors';

    public function testAFolderBelowTheFreeBytesFloorReadsAsPausedForFreeSpaceInHumanUnits(): void
    {
        $held = $this->heldFor(new DiskHold(DiskFloor::Bytes, '/data', 1_288_490_189, 2_147_483_648))->now();

        self::assertSame([
            'short' => 'New work paused: the disk is low on space',
            'title' => 'New work is paused because the disk is low on free space.',
            'text' => 'The disk of /data has 1.2 GiB free, below the floor of 2 GiB (disk.min_free_bytes). Work already running goes on, and new work starts again by itself once space is back.',
            'broken' => false,
        ], $held);
    }

    public function testAFolderBelowTheFreeInodesFloorReadsAsPausedForFreeFileSlots(): void
    {
        $held = $this->heldFor(new DiskHold(DiskFloor::Inodes, '/data', 5_000, 100_000))->now();

        self::assertSame('New work is paused because the disk is low on free file slots (inodes).', $held['title'] ?? null);
        self::assertSame('The disk of /data has 5,000 free file slots, below the floor of 100,000 (disk.min_free_inodes). Work already running goes on, and new work starts again by itself once space is back.', $held['text'] ?? null);
    }

    public function testAReadingOneByteBelowItsFloorIsRoundedDownSoItNeverReadsAsTheFloor(): void
    {
        $held = $this->heldFor(new DiskHold(DiskFloor::Bytes, '/data', 2_147_483_647, 2_147_483_648))->now();

        self::assertStringStartsWith('The disk of /data has 1.9 GiB free, below the floor of 2 GiB', $held['text'] ?? '');
    }

    public function testASmallReadingIsShownInWholeBytes(): void
    {
        $held = $this->heldFor(new DiskHold(DiskFloor::Bytes, '/data', 512, 1_024))->now();

        self::assertStringStartsWith('The disk of /data has 512 bytes free, below the floor of 1 KiB', $held['text'] ?? '');
    }

    public function testADiskAboveBothFloorsReadsAsNothingToShow(): void
    {
        self::assertNull($this->heldFor(null)->now());
    }

    public function testTheLineIsGoneOnTheNextReadOnceSpaceIsBack(): void
    {
        $disk = self::createStub(DiskSpace::class);
        $disk->method('holdAt')->willReturn(new DiskHold(DiskFloor::Bytes, '/data', 1, 2), null);
        $held = new HeldForSpace($disk, self::MIRRORS);

        self::assertSame([true, false], [null !== $held->now(), null !== $held->now()]);
    }

    public function testTheMirrorsFolderIsMeasuredAtTheNearestFolderThatExists(): void
    {
        $disk = $this->createMock(DiskSpace::class);
        $disk->expects($this->once())->method('holdAt')->with(sys_get_temp_dir())->willReturn(null);

        new HeldForSpace($disk, sys_get_temp_dir().'/intakt-no-mirrors-'.bin2hex(random_bytes(6)))->now();
    }

    public function testAnUnreadableDiskReadsAsABrokenLineNamingTheReasonInsteadOfFailingThePage(): void
    {
        $disk = self::createStub(DiskSpace::class);
        $disk->method('holdAt')->willThrowException(new FreeSpaceUnreadable('/data', 'df exited with 1'));

        self::assertSame([
            'short' => 'Free disk space unknown',
            'title' => 'The free disk space cannot be read.',
            'text' => 'The free space of /data cannot be read: df exited with 1. A mirror update or build clone on that disk fails until it can be read.',
            'broken' => true,
        ], new HeldForSpace($disk, self::MIRRORS)->now());
    }

    public function testAnInodeReadThatTimesOutReadsAsABrokenLineNamingDf(): void
    {
        $disk = self::createStub(DiskSpace::class);
        $disk->method('holdAt')->willThrowException(new ProcessTimedOutException(new Process(['df'])->setTimeout(10), ProcessTimedOutException::TYPE_GENERAL));

        self::assertStringStartsWith(sprintf('The free space of %s cannot be read: df timed out after 10 seconds.', sys_get_temp_dir()), new HeldForSpace($disk, sys_get_temp_dir())->now()['text'] ?? '');
    }

    private function heldFor(?DiskHold $hold): HeldForSpace
    {
        $disk = self::createStub(DiskSpace::class);
        $disk->method('holdAt')->willReturn($hold);

        return new HeldForSpace($disk, self::MIRRORS);
    }
}
