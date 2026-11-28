<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Disk;

use Intakt\Core\Disk\Contract\DiskFloor;
use Intakt\Core\Disk\Contract\DiskHold;
use Intakt\Core\Disk\Contract\DiskServices;
use Intakt\Core\Disk\Contract\DiskSpace;
use Intakt\Core\Disk\Contract\NearestFolder;
use Intakt\Core\Disk\Contract\NewWorkHeld;
use Intakt\Core\Disk\Infrastructure\DiskConfiguration;
use Intakt\Core\Disk\Infrastructure\FlooredDiskSpace;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\Domain\TaskBranch;
use Intakt\Core\Git\UseCase\MakeBuildClone\BuildCloneNotMade;
use Intakt\Core\Git\UseCase\MakeBuildClone\MakeBuildClone;
use Intakt\Core\Git\UseCase\UpdateMirror\MirrorNotUpdated;
use Intakt\Core\Git\UseCase\UpdateMirror\UpdateMirror;
use Intakt\Core\Watcher\Domain\PendingHead;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\FailedHeads;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\PendingHeads;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Core\Watcher\UseCase\FindChangedPullRequests\RecordedForge;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(FlooredDiskSpace::class)]
#[CoversClass(UpdateMirror::class)]
#[CoversClass(MakeBuildClone::class)]
#[UsesClass(DiskHold::class)]
#[UsesClass(DiskConfiguration::class)]
#[UsesClass(ControlledFreeSpaceReader::class)]
#[UsesClass(Mirrors::class)]
#[UsesClass(TaskBranch::class)]
#[UsesClass(MirrorNotUpdated::class)]
#[UsesClass(BuildCloneNotMade::class)]
#[UsesClass(NearestFolder::class)]
#[UsesClass(FailedHeads::class)]
#[UsesClass(PendingHeads::class)]
#[UsesClass(PendingHead::class)]
#[UsesClass(NewWorkHeld::class)]
final class DiskSpaceOnTheContainerTest extends KernelTestCase
{
    private const int BYTES = DiskConfiguration::DEFAULT_MIN_FREE_BYTES;

    private const int INODES = DiskConfiguration::DEFAULT_MIN_FREE_INODES;

    private const array READINGS = [
        'below the byte floor' => [self::BYTES - 1, self::INODES],
        'below the inode floor' => [self::BYTES, self::INODES - 1],
        'at both floors' => [self::BYTES, self::INODES],
        'no inode count' => [self::BYTES, null],
    ];

    private string $scratch;

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
        foreach (self::READINGS as [$bytes, $inodes]) {
            $reader->report($bytes, $inodes);
            $held[] = $disk->holdAt($this->scratch.'/mirrors');
        }

        self::assertEquals([
            new DiskHold(DiskFloor::Bytes, $this->scratch.'/mirrors', self::BYTES - 1, self::BYTES),
            new DiskHold(DiskFloor::Inodes, $this->scratch.'/mirrors', self::INODES - 1, self::INODES),
            null,
            null,
        ], $held);
    }

    public function testTheWiredMirrorUpdateHoldsAtEachFloorAndResumesAboveIt(): void
    {
        $remote = new TemporaryRepository();
        $remote->commitFile('a.txt', 'one');
        self::getContainer()->set(Mirrors::class, new Mirrors($this->scratch.'/mirrors'));
        $reader = self::getContainer()->get(ControlledFreeSpaceReader::class);
        $mirrors = self::getContainer()->get(UpdateMirror::class);
        new Filesystem()->mkdir($this->scratch.'/mirrors');

        $steps = [];

        try {
            foreach (self::READINGS as $case => [$bytes, $inodes]) {
                $reader->report($bytes, $inodes);
                $steps[$case] = $this->outcome(static fn (): string => $mirrors->update('alpha', $remote->path));
            }
        } finally {
            $remote->remove();
        }

        self::assertSame([
            'below the byte floor' => sprintf('held: the mirror at %s/mirrors/alpha.git was not updated: the disk of %1$s/mirrors holds new work: %d free bytes, below the floor of %d', $this->scratch, self::BYTES - 1, self::BYTES),
            'below the inode floor' => sprintf('held: the mirror at %s/mirrors/alpha.git was not updated: the disk of %1$s/mirrors holds new work: %d free inodes, below the floor of %d', $this->scratch, self::INODES - 1, self::INODES),
            'at both floors' => 'went ahead',
            'no inode count' => 'went ahead',
        ], $steps);
    }

    public function testTheWiredBuildCloneHoldsAtEachFloorAndResumesAboveIt(): void
    {
        $remote = new TemporaryRepository();
        $remote->commitFile('a.txt', 'one');
        self::getContainer()->set(Mirrors::class, new Mirrors($this->scratch.'/mirrors'));
        $reader = self::getContainer()->get(ControlledFreeSpaceReader::class);
        $clones = self::getContainer()->get(MakeBuildClone::class);

        try {
            self::getContainer()->get(UpdateMirror::class)->update('alpha', $remote->path);
        } finally {
            $remote->remove();
        }

        new Filesystem()->mkdir($this->scratch.'/clones');
        $steps = [];
        foreach (self::READINGS as $case => [$bytes, $inodes]) {
            $reader->report($bytes, $inodes);
            $steps[$case] = $this->outcome(fn () => $clones->make('alpha', new TaskBranch('feat/task'), $this->scratch.'/clones/'.md5($case)));
        }

        self::assertSame([
            'below the byte floor' => sprintf('held: the build clone at %s/clones/%s was not made: the disk of %1$s/clones holds new work: %d free bytes, below the floor of %d', $this->scratch, md5('below the byte floor'), self::BYTES - 1, self::BYTES),
            'below the inode floor' => sprintf('held: the build clone at %s/clones/%s was not made: the disk of %1$s/clones holds new work: %d free inodes, below the floor of %d', $this->scratch, md5('below the inode floor'), self::INODES - 1, self::INODES),
            'at both floors' => 'went ahead',
            'no inode count' => 'went ahead',
        ], $steps);
    }

    public function testTheWiredWatcherCountsNoFailureForAHeadHeldAtEachFloor(): void
    {
        EmptyDatabase::rowsIn(self::getContainer());
        $head = RecordedForge::change('7', 'a', '2026-11-04T09:00:00Z');
        $pending = self::getContainer()->get(PendingHeads::class);
        $pending->enqueue('alpha', [$head]);
        $remote = new TemporaryRepository();
        $remote->commitFile('a.txt', 'one');
        self::getContainer()->set(Mirrors::class, new Mirrors($this->scratch.'/mirrors'));
        $reader = self::getContainer()->get(ControlledFreeSpaceReader::class);
        $mirrors = self::getContainer()->get(UpdateMirror::class);
        $failed = self::getContainer()->get(FailedHeads::class);
        new Filesystem()->mkdir($this->scratch.'/mirrors');

        $failures = [];

        try {
            foreach (self::READINGS as $case => [$bytes, $inodes]) {
                $reader->report($bytes, $inodes);
                $failures[$case] = $this->failuresAfter(static fn (): string => $mirrors->update('alpha', $remote->path), $failed, $pending, $head);
            }
        } finally {
            $remote->remove();
        }

        self::assertSame(['below the byte floor' => 'held, 0 failures', 'below the inode floor' => 'held, 0 failures', 'at both floors' => 'went ahead', 'no inode count' => 'went ahead'], $failures);
    }

    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir().'/intakt-disk-floors-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->scratch);
        parent::tearDown();
    }

    private function outcome(callable $step): string
    {
        try {
            $step();
        } catch (MirrorNotUpdated|BuildCloneNotMade $held) {
            return 'held: '.$held->getMessage();
        }

        return 'went ahead';
    }

    private function failuresAfter(callable $step, FailedHeads $failed, PendingHeads $pending, OpenChange $head): string
    {
        try {
            $step();
        } catch (MirrorNotUpdated $held) {
            $failed->backOff('alpha', $head, $held);

            return sprintf('held, %d failures', $pending->failuresOf('alpha', $head));
        }

        return 'went ahead';
    }
}
