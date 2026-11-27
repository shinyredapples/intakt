<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Disk;

use Intakt\Core\Disk\Contract\DiskFloor;
use Intakt\Core\Disk\Contract\DiskHold;
use Intakt\Core\Disk\Contract\DiskServices;
use Intakt\Core\Disk\Contract\DiskSpace;
use Intakt\Core\Disk\Infrastructure\DiskConfiguration;
use Intakt\Core\Disk\Infrastructure\FlooredDiskSpace;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\Domain\TaskBranch;
use Intakt\Core\Git\UseCase\MakeBuildClone\BuildCloneNotMade;
use Intakt\Core\Git\UseCase\MakeBuildClone\MakeBuildClone;
use Intakt\Core\Git\UseCase\UpdateMirror\MirrorNotUpdated;
use Intakt\Core\Git\UseCase\UpdateMirror\UpdateMirror;
use Intakt\Tests\Core\Git\TemporaryRepository;
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
}
