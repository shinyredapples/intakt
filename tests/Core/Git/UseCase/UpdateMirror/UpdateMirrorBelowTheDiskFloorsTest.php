<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\UpdateMirror;

use Intakt\Core\Disk\Contract\DiskHold;
use Intakt\Core\Disk\Contract\FreeSpaceUnreadable;
use Intakt\Core\Disk\Contract\NearestFolder;
use Intakt\Core\Disk\Infrastructure\FlooredDiskSpace;
use Intakt\Core\Disk\Infrastructure\FreeSpaceReader;
use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\NetworkGit;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\UseCase\UpdateMirror\MirrorNotUpdated;
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

#[CoversClass(UpdateMirror::class)]
#[CoversClass(MirrorNotUpdated::class)]
#[CoversClass(NearestFolder::class)]
#[UsesClass(Mirrors::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(NetworkGit::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(FlooredDiskSpace::class)]
#[UsesClass(DiskHold::class)]
#[UsesClass(FreeSpaceUnreadable::class)]
#[UsesClass(ControlledFreeSpaceReader::class)]
final class UpdateMirrorBelowTheDiskFloorsTest extends TestCase
{
    private const int BYTES = 1000;

    private const int INODES = 100;

    private string $mirrors;

    private TemporaryRepository $remote;

    private ControlledFreeSpaceReader $reader;

    public function testAFirstMirrorUpdateBelowTheByteFloorIsMeasuredAtTheNearestFolderThatExistsAndMakesNoFolder(): void
    {
        $this->reader->report(self::BYTES - 1, self::INODES);

        $failure = $this->heldUpdate();

        self::assertSame(sprintf('the mirror at %s/alpha.git was not updated: the disk of %s holds new work: %d free bytes, below the floor of %d', $this->mirrors, dirname($this->mirrors), self::BYTES - 1, self::BYTES), $failure->getMessage());
        self::assertDirectoryDoesNotExist($this->mirrors);
    }

    public function testAnExistingMirrorIsMeasuredOnItsOwnDiskAndHeldWhenOnlyThatDiskIsBelowTheFloor(): void
    {
        $path = $this->update();
        $before = $this->remote->objectAt('main');
        $this->remote->commitFile('b.txt', 'two');
        $reader = self::createStub(FreeSpaceReader::class);
        $reader->method('freeInodes')->willReturn(self::INODES);
        $reader->method('freeBytes')->willReturnCallback(static fn (string $folder): int => $folder === $path ? 0 : self::BYTES);

        try {
            $this->updateReadingThrough($reader);
            self::fail('The update fetched into a mirror whose own disk is below the floor.');
        } catch (MirrorNotUpdated $held) {
            self::assertStringContainsString(sprintf('the disk of %s holds new work', $path), $held->getMessage());
        }

        self::assertSame($before, $this->mainOf($path));
    }

    public function testAFolderThatIsNoMirrorIsHeldWhenOnlyTheMirrorsFolderWhereItsReplacementIsClonedIsBelowTheFloor(): void
    {
        new Filesystem()->mkdir($this->mirrors.'/alpha.git');
        $reader = self::createStub(FreeSpaceReader::class);
        $reader->method('freeInodes')->willReturn(self::INODES);
        $reader->method('freeBytes')->willReturnCallback(fn (string $folder): int => $folder === $this->mirrors ? 0 : self::BYTES);

        try {
            $this->updateReadingThrough($reader);
            self::fail('The update cloned into staging in a mirrors folder whose disk is below the floor.');
        } catch (MirrorNotUpdated $held) {
            self::assertStringContainsString(sprintf('the disk of %s holds new work', $this->mirrors), $held->getMessage());
        }

        self::assertSame(['alpha.git'], array_values(array_diff(scandir($this->mirrors), ['.', '..'])));
        self::assertFileDoesNotExist($this->mirrors.'/alpha.git/HEAD');
    }

    public function testAMirrorRemovedWhileItsDiskIsMeasuredIsNotClonedAgainIntoAMirrorsFolderBelowTheFloor(): void
    {
        $path = $this->update();
        $reader = self::createStub(FreeSpaceReader::class);
        $reader->method('freeInodes')->willReturn(self::INODES);
        $reader->method('freeBytes')->willReturnCallback(function (string $folder) use ($path): int {
            if ($folder !== $path) {
                return 0;
            }

            new Filesystem()->rename($path, $this->mirrors.'/moved.git');

            return self::BYTES;
        });

        try {
            $this->updateReadingThrough($reader);
            self::fail('The update cloned into staging in a mirrors folder it did not measure.');
        } catch (MirrorNotUpdated $failed) {
            self::assertStringContainsString('changed while its disk was measured', $failed->getMessage());
        }

        self::assertSame(['moved.git'], array_values(array_diff(scandir($this->mirrors), ['.', '..'])));
    }

    public function testAFolderThatIsNoMirrorIsReplacedWhenOnlyThatFolderReadsBelowTheFloor(): void
    {
        new Filesystem()->mkdir($this->mirrors.'/alpha.git');
        $reader = self::createStub(FreeSpaceReader::class);
        $reader->method('freeInodes')->willReturn(self::INODES);
        $reader->method('freeBytes')->willReturnCallback(fn (string $folder): int => $folder === $this->mirrors.'/alpha.git' ? 0 : self::BYTES);

        $path = $this->updateReadingThrough($reader);

        self::assertSame($this->remote->objectAt('main'), $this->mainOf($path));
    }

    public function testARefreshForWorkAlreadyRunningGoesAheadBelowTheFloors(): void
    {
        $this->update();
        $this->remote->commitFile('b.txt', 'two');
        $this->reader->report(0, 0);

        $path = $this->updater($this->reader)->refresh('alpha', $this->remote->path);

        self::assertSame($this->remote->objectAt('main'), $this->mainOf($path));
    }

    public function testAMirrorUpdateWhoseInodeReadTimesOutFailsNamingDfAndNotGit(): void
    {
        $reader = self::createStub(FreeSpaceReader::class);
        $reader->method('freeBytes')->willReturn(self::BYTES);
        $reader->method('freeInodes')->willThrowException(new ProcessTimedOutException(new Process(['df'], null, null, null, 10), ProcessTimedOutException::TYPE_GENERAL));

        try {
            $this->updateReadingThrough($reader);
            self::fail('The update went ahead on a disk whose inodes could not be read.');
        } catch (MirrorNotUpdated $failure) {
            self::assertStringEndsWith(sprintf('The free space of %s cannot be read: df timed out after 10 seconds.', dirname($this->mirrors)), $failure->getMessage());
            self::assertNull($failure->getPrevious());
        }

        self::assertDirectoryDoesNotExist($this->mirrors);
    }

    public function testAMirrorUpdateBelowTheInodeFloorIsHeldAndFetchesNothing(): void
    {
        $path = $this->update();
        $before = $this->remote->objectAt('main');
        $this->remote->commitFile('b.txt', 'two');
        $this->reader->report(self::BYTES, self::INODES - 1);

        $failure = $this->heldUpdate();

        self::assertStringEndsWith(sprintf('%d free inodes, below the floor of %d', self::INODES - 1, self::INODES), $failure->getMessage());
        self::assertSame($before, $this->mainOf($path));
    }

    public function testAHeldMirrorUpdateGoesAheadOnceTheReadingsAreAtTheFloors(): void
    {
        $this->reader->report(self::BYTES - 1, self::INODES);
        $this->heldUpdate();
        $this->reader->report(self::BYTES, self::INODES);

        $path = $this->update();

        self::assertSame($this->remote->objectAt('main'), $this->mainOf($path));
    }

    public function testAMirrorUpdateWhoseDiskCannotBeReadFailsAsMirrorNotUpdatedWithTheReason(): void
    {
        $reader = self::createStub(FreeSpaceReader::class);
        $reader->method('freeBytes')->willThrowException(new FreeSpaceUnreadable($this->mirrors, 'the file system is gone'));

        try {
            $this->updateReadingThrough($reader);
            self::fail('The update went ahead on a disk it could not read.');
        } catch (MirrorNotUpdated $failure) {
            self::assertStringEndsWith(sprintf('The free space of %s cannot be read: the file system is gone.', $this->mirrors), $failure->getMessage());
            self::assertInstanceOf(FreeSpaceUnreadable::class, $failure->getPrevious());
        }

        self::assertDirectoryDoesNotExist($this->mirrors);
    }

    protected function setUp(): void
    {
        $this->mirrors = sys_get_temp_dir().'/intakt-held-mirrors-'.bin2hex(random_bytes(6));
        $this->remote = new TemporaryRepository();
        $this->remote->commitFile('a.txt', 'one');
        $this->reader = new ControlledFreeSpaceReader();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->mirrors);
        $this->remote->remove();
    }

    private function heldUpdate(): MirrorNotUpdated
    {
        try {
            $this->update();
        } catch (MirrorNotUpdated $held) {
            return $held;
        }

        self::fail('The update went ahead below a disk floor.');
    }

    private function update(): string
    {
        return $this->updateReadingThrough($this->reader);
    }

    private function updateReadingThrough(FreeSpaceReader $reader): string
    {
        return $this->updater($reader)->update('alpha', $this->remote->path);
    }

    private function updater(FreeSpaceReader $reader): UpdateMirror
    {
        $network = new NetworkGit(new RecordingClock(), new NullLogger());
        $disk = new FlooredDiskSpace($reader, self::BYTES, self::INODES);

        return new UpdateMirror(new HardenedGit(), $network, new Filesystem(), new LockFactory(new InMemoryStore()), new Mirrors($this->mirrors), $disk);
    }

    private function mainOf(string $mirror): string
    {
        $process = new Process(['git', 'rev-parse', 'refs/heads/main'], $mirror, ['GIT_DIR' => false, 'GIT_WORK_TREE' => false]);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
