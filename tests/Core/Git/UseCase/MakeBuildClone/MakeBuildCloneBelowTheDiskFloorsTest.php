<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\MakeBuildClone;

use Intakt\Core\Disk\Contract\DiskFloor;
use Intakt\Core\Disk\Contract\DiskHold;
use Intakt\Core\Disk\Contract\DiskSpace;
use Intakt\Core\Disk\Contract\FreeSpaceUnreadable;
use Intakt\Core\Disk\Contract\NearestFolder;
use Intakt\Core\Disk\Contract\NewWorkHeld;
use Intakt\Core\Disk\Infrastructure\FlooredDiskSpace;
use Intakt\Core\Disk\Infrastructure\FreeSpaceReader;
use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\NetworkGit;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\Domain\TaskBranch;
use Intakt\Core\Git\UseCase\MakeBuildClone\BuildCloneNotMade;
use Intakt\Core\Git\UseCase\MakeBuildClone\MakeBuildClone;
use Intakt\Core\Git\UseCase\UpdateMirror\UpdateMirror;
use Intakt\Tests\Core\Disk\ControlledFreeSpaceReader;
use Intakt\Tests\Core\Git\RecordingClock;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

#[CoversClass(MakeBuildClone::class)]
#[CoversClass(BuildCloneNotMade::class)]
#[CoversClass(NearestFolder::class)]
#[UsesClass(Mirrors::class)]
#[UsesClass(TaskBranch::class)]
#[UsesClass(UpdateMirror::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(NetworkGit::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(FlooredDiskSpace::class)]
#[UsesClass(DiskHold::class)]
#[UsesClass(NewWorkHeld::class)]
#[UsesClass(FreeSpaceUnreadable::class)]
#[UsesClass(ControlledFreeSpaceReader::class)]
final class MakeBuildCloneBelowTheDiskFloorsTest extends TestCase
{
    private const int BYTES = 1000;

    private const int INODES = 100;

    private string $mirrors;

    private string $clones;

    private string $clone;

    private TemporaryRepository $remote;

    private ControlledFreeSpaceReader $reader;

    public function testAFirstBuildCloneBelowTheByteFloorIsMeasuredAtTheNearestFolderThatExistsAndMakesNoFolder(): void
    {
        $this->reader->report(self::BYTES - 1, self::INODES);

        $failure = $this->heldClone();

        self::assertSame(sprintf('the build clone at %s was not made: the disk of %s holds new work: %d free bytes, below the floor of %d', $this->clone, dirname($this->clones), self::BYTES - 1, self::BYTES), $failure->getMessage());
        self::assertDirectoryDoesNotExist($this->clones);
    }

    public function testABuildCloneIsMeasuredAtTheClonesFolderOnceItExists(): void
    {
        new Filesystem()->mkdir($this->clones);
        $this->reader->report(self::BYTES - 1, self::INODES);

        $failure = $this->heldClone();

        self::assertStringContainsString(sprintf('the disk of %s holds new work', $this->clones), $failure->getMessage());
        self::assertFileDoesNotExist($this->clone);
    }

    public function testABuildCloneWhoseInodeReadTimesOutFailsNamingDfAndNotGit(): void
    {
        $reader = self::createStub(FreeSpaceReader::class);
        $reader->method('freeBytes')->willReturn(self::BYTES);
        $reader->method('freeInodes')->willThrowException(new ProcessTimedOutException(new Process(['df'], null, null, null, 10), ProcessTimedOutException::TYPE_GENERAL));

        try {
            $this->makeClone(new FlooredDiskSpace($reader, self::BYTES, self::INODES));
            self::fail('The clone was made on a disk whose inodes could not be read.');
        } catch (BuildCloneNotMade $failure) {
            self::assertStringEndsWith(sprintf('The free space of %s cannot be read: df timed out after 10 seconds.', dirname($this->clones)), $failure->getMessage());
            self::assertNull($failure->getPrevious());
        }

        self::assertDirectoryDoesNotExist($this->clones);
    }

    public function testABuildCloneBelowTheInodeFloorIsHeldAndItsFolderIsNeverCreated(): void
    {
        $this->reader->report(self::BYTES, self::INODES - 1);

        $failure = $this->heldClone();

        self::assertStringEndsWith(sprintf('%d free inodes, below the floor of %d', self::INODES - 1, self::INODES), $failure->getMessage());
        self::assertFileDoesNotExist($this->clone);
    }

    public function testAHeldBuildCloneNamesTheDiskHoldAsItsCause(): void
    {
        $this->reader->report(self::BYTES, self::INODES - 1);

        $cause = $this->heldClone()->getPrevious();

        self::assertInstanceOf(NewWorkHeld::class, $cause);
        self::assertSame(DiskFloor::Inodes, $cause->hold->floor);
    }

    public function testAHeldBuildCloneIsMadeOnceTheReadingsAreAtTheFloors(): void
    {
        $this->reader->report(self::BYTES - 1, self::INODES - 1);
        $this->heldClone();
        $this->reader->report(self::BYTES, self::INODES);

        $this->makeClone(new FlooredDiskSpace($this->reader, self::BYTES, self::INODES));

        self::assertSame('one', file_get_contents($this->clone.'/a.txt'));
    }

    public function testABuildCloneWhoseDiskCannotBeReadFailsAsBuildCloneNotMadeWithTheReason(): void
    {
        $reader = self::createStub(FreeSpaceReader::class);
        $reader->method('freeBytes')->willThrowException(new FreeSpaceUnreadable($this->clones, 'the file system is gone'));

        try {
            $this->makeClone(new FlooredDiskSpace($reader, self::BYTES, self::INODES));
            self::fail('The clone was made on a disk that could not be read.');
        } catch (BuildCloneNotMade $failure) {
            self::assertStringEndsWith(sprintf('The free space of %s cannot be read: the file system is gone.', $this->clones), $failure->getMessage());
            self::assertInstanceOf(FreeSpaceUnreadable::class, $failure->getPrevious());
        }

        self::assertFileDoesNotExist($this->clone);
    }

    protected function setUp(): void
    {
        $this->mirrors = sys_get_temp_dir().'/intakt-held-clone-'.bin2hex(random_bytes(6));
        $this->clones = $this->mirrors.'-clones';
        $this->clone = $this->clones.'/42';
        $this->remote = new TemporaryRepository();
        $this->remote->commitFile('a.txt', 'one');
        $this->reader = new ControlledFreeSpaceReader();
        $network = new NetworkGit(new RecordingClock(), new NullLogger());
        new UpdateMirror(new HardenedGit(), $network, new Filesystem(), new LockFactory(new InMemoryStore()), new Mirrors($this->mirrors), new FlooredDiskSpace($this->reader, 1, 1))->update('alpha', $this->remote->path);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove([$this->mirrors, $this->clones]);
        $this->remote->remove();
    }

    private function heldClone(): BuildCloneNotMade
    {
        try {
            $this->makeClone(new FlooredDiskSpace($this->reader, self::BYTES, self::INODES));
        } catch (BuildCloneNotMade $held) {
            return $held;
        }

        self::fail('The clone was made below a disk floor.');
    }

    private function makeClone(DiskSpace $disk): void
    {
        new MakeBuildClone(new HardenedGit(), new Filesystem(), new Mirrors($this->mirrors), $disk)->make('alpha', new TaskBranch('feat/task'), $this->clone);
    }
}
