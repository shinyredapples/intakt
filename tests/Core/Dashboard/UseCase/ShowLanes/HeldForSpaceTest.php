<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ShowLanes;

use Intakt\Core\Dashboard\UseCase\ShowLanes\HeldForSpace;
use Intakt\Core\Disk\Contract\DiskFloor;
use Intakt\Core\Disk\Contract\DiskHold;
use Intakt\Core\Disk\Contract\DiskSpace;
use Intakt\Core\Disk\Contract\FreeSpaceUnreadable;
use Intakt\Core\Disk\Contract\NearestFolder;
use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\MirrorFolders;
use Intakt\Core\Git\Contract\NetworkGit;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\UseCase\UpdateMirror\MirrorsOnDisk;
use Intakt\Core\Git\UseCase\UpdateMirror\UpdateMirror;
use Intakt\Tests\Core\Git\RecordingClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

#[CoversClass(HeldForSpace::class)]
#[UsesClass(DiskHold::class)]
#[UsesClass(NearestFolder::class)]
#[UsesClass(FreeSpaceUnreadable::class)]
#[UsesClass(MirrorsOnDisk::class)]
#[UsesClass(UpdateMirror::class)]
#[UsesClass(Mirrors::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(NetworkGit::class)]
#[UsesClass(GitCall::class)]
final class HeldForSpaceTest extends TestCase
{
    private const string MIRRORS = '/data/mirrors';

    private string $root;

    public function testAFolderBelowTheFreeBytesFloorReadsAsPausedForFreeSpaceInHumanUnits(): void
    {
        $held = $this->heldFor(new DiskHold(DiskFloor::Bytes, '/data', 1_288_490_189, 2_147_483_648))->now();

        self::assertSame([
            'short' => 'New work paused: the disk is low on space',
            'title' => 'New work is paused because the disk is low on free space.',
            'text' => 'The disk of /data has 1.2 GiB free, below the floor of 2 GiB (disk.min_free_bytes). Work already running goes on. This line goes away by itself once space is back; a step that was refused meanwhile must be run again.',
            'broken' => false,
        ], $held);
    }

    public function testAFolderBelowTheFreeInodesFloorReadsAsPausedForFreeFileSlots(): void
    {
        $held = $this->heldFor(new DiskHold(DiskFloor::Inodes, '/data', 5_000, 100_000))->now();

        self::assertSame(
            ['New work is paused because the disk is low on free file slots (inodes).', 'The disk of /data has 5,000 free file slots, below the floor of 100,000 (disk.min_free_inodes). Work already running goes on. This line goes away by itself once space is back; a step that was refused meanwhile must be run again.'],
            [$held['title'] ?? null, $held['text'] ?? null],
        );
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
        $held = new HeldForSpace($disk, self::noMirrors(), self::MIRRORS);

        self::assertSame([true, false], [null !== $held->now(), null !== $held->now()]);
    }

    public function testTheMirrorsFolderIsMeasuredAtTheNearestFolderThatExists(): void
    {
        $disk = $this->createMock(DiskSpace::class);
        $disk->expects($this->once())->method('holdAt')->with(sys_get_temp_dir())->willReturn(null);

        new HeldForSpace($disk, self::noMirrors(), sys_get_temp_dir().'/intakt-no-mirrors-'.bin2hex(random_bytes(6)))->now();
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
        ], new HeldForSpace($disk, self::noMirrors(), self::MIRRORS)->now());
    }

    public function testAnInodeReadThatTimesOutReadsAsABrokenLineNamingDf(): void
    {
        $disk = self::createStub(DiskSpace::class);
        $disk->method('holdAt')->willThrowException(new ProcessTimedOutException(new Process(['df'])->setTimeout(10), ProcessTimedOutException::TYPE_GENERAL));

        self::assertStringStartsWith(sprintf('The free space of %s cannot be read: df timed out after 10 seconds.', sys_get_temp_dir()), new HeldForSpace($disk, self::noMirrors(), sys_get_temp_dir())->now()['text'] ?? '');
    }

    public function testRoundingThatMakesTheReadingAndTheFloorEqualShowsExactBytes(): void
    {
        $held = $this->heldFor(new DiskHold(DiskFloor::Bytes, '/data', 2_046, 2_047))->now();

        self::assertStringStartsWith('The disk of /data has 2,046 bytes free, below the floor of 2,047 bytes', $held['text'] ?? '');
    }

    public function testOneByteReadsInTheSingular(): void
    {
        $held = $this->heldFor(new DiskHold(DiskFloor::Bytes, '/data', 1, 2_147_483_648))->now();

        self::assertStringStartsWith('The disk of /data has 1 byte free', $held['text'] ?? '');
    }

    public function testAMirrorOnADiskOfItsOwnBelowTheFloorIsShownEvenWhenTheMirrorsFolderIsFine(): void
    {
        $mirror = $this->root.'/alpha.git';
        new Process(['git', 'init', '--bare', '--quiet', $mirror])->mustRun();

        $held = new HeldForSpace($this->heldOnlyAt($mirror), $this->mirrorsOnDisk(), $this->root)->now();

        self::assertStringContainsString('The disk of '.$mirror.' has 0 bytes free', $held['text'] ?? '');
    }

    public function testAFolderInTheMirrorsFolderThatIsNoMirrorIsNotMeasuredSoItsLowDiskShowsNoLine(): void
    {
        $backup = $this->root.'/backup';
        $empty = $this->root.'/alpha.git';
        mkdir($backup, 0o777, true);
        mkdir($empty);
        $asked = [];
        $disk = self::createStub(DiskSpace::class);
        $disk->method('holdAt')->willReturnCallback(static function (string $folder) use (&$asked, $backup, $empty): ?DiskHold {
            $asked[] = $folder;

            return in_array($folder, [$backup, $empty], true) ? new DiskHold(DiskFloor::Inodes, $folder, 2, 100) : null;
        });

        $held = new HeldForSpace($disk, $this->mirrorsOnDisk(), $this->root)->now();

        self::assertSame([null, [$this->root]], [$held, $asked]);
    }

    public function testAMirrorBelowTheFloorIsShownUnderAnInstallPathWithBrackets(): void
    {
        $this->root .= '/install[local]/mirrors';
        $mirror = $this->root.'/alpha.git';
        new Process(['git', 'init', '--bare', '--quiet', $mirror])->mustRun();

        $held = new HeldForSpace($this->heldOnlyAt($mirror), $this->mirrorsOnDisk(), $this->root)->now();

        self::assertStringContainsString('The disk of '.$mirror.' has 0 bytes free', $held['text'] ?? '');
    }

    public function testAHoldOnAMirrorIsShownEvenWhenTheMirrorsFolderCannotBeRead(): void
    {
        mkdir($mirror = $this->root.'/alpha.git', 0o777, true);
        $disk = self::createStub(DiskSpace::class);
        $disk->method('holdAt')->willReturnCallback(static fn (string $folder): DiskHold => $mirror === $folder ? new DiskHold(DiskFloor::Inodes, $folder, 2, 100) : throw new FreeSpaceUnreadable($folder, 'unreadable parent disk'));

        $held = new HeldForSpace($disk, self::mirrorsAt($mirror), $this->root)->now();

        self::assertStringStartsWith('The disk of '.$mirror.' has 2 free file slots', $held['text'] ?? '');
    }

    public function testAMirrorWhoseDiskCannotBeReadShowsTheBrokenLineWhenNoFolderIsHeld(): void
    {
        mkdir($mirror = $this->root.'/alpha.git', 0o777, true);
        $disk = self::createStub(DiskSpace::class);
        $disk->method('holdAt')->willReturnCallback(static fn (string $folder): ?DiskHold => $mirror === $folder ? throw new FreeSpaceUnreadable($folder, 'df exited with 1') : null);

        $held = new HeldForSpace($disk, self::mirrorsAt($mirror), $this->root)->now();

        self::assertSame([true, 'The free space of '.$mirror.' cannot be read: df exited with 1. A mirror update or build clone on that disk fails until it can be read.'], [$held['broken'] ?? null, $held['text'] ?? null]);
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/intakt-held-for-space-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove(strstr($this->root, '/install[local]', true) ?: $this->root);
    }

    private function heldOnlyAt(string $held): DiskSpace
    {
        $disk = self::createStub(DiskSpace::class);
        $disk->method('holdAt')->willReturnCallback(static fn (string $folder): ?DiskHold => $folder === $held ? new DiskHold(DiskFloor::Bytes, $folder, 0, 1_000) : null);

        return $disk;
    }

    // The real list, so these tests pin the page to the folders a mirror update measures.
    private function mirrorsOnDisk(): MirrorsOnDisk
    {
        $mirrors = new Mirrors($this->root);
        $update = new UpdateMirror(new HardenedGit(), new NetworkGit(new RecordingClock(), new NullLogger()), new Filesystem(), new LockFactory(new InMemoryStore()), $mirrors, self::createStub(DiskSpace::class));

        return new MirrorsOnDisk($update, $mirrors);
    }

    private static function noMirrors(): MirrorFolders
    {
        return self::mirrorsAt();
    }

    private static function mirrorsAt(string ...$paths): MirrorFolders
    {
        $mirrors = self::createStub(MirrorFolders::class);
        $mirrors->method('existing')->willReturn(array_values($paths));

        return $mirrors;
    }

    private function heldFor(?DiskHold $hold): HeldForSpace
    {
        $disk = self::createStub(DiskSpace::class);
        $disk->method('holdAt')->willReturn($hold);

        return new HeldForSpace($disk, self::noMirrors(), self::MIRRORS);
    }
}
