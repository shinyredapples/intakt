<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Disk;

use Intakt\Core\Disk\Contract\DiskHeld;
use Intakt\Core\Disk\Contract\DiskServices;
use Intakt\Core\Disk\Contract\NewWorkHeld;
use Intakt\Core\Disk\Domain\FloorCheck;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

// The commands as the container wires them, with the configured floors and only the free-space reading controlled, so a hold and its recovery are shown on the install itself.
#[CoversClass(FloorCheck::class)]
#[UsesClass(DiskHeld::class)]
#[UsesClass(NewWorkHeld::class)]
final class DiskFloorsOnTheInstallTest extends KernelTestCase
{
    private TemporaryRepository $remote;

    private string $project;

    private string $clone;

    private string $mirrors;

    private FreeSpaceInMemory $space;

    private int $bytesFloor;

    private int $inodesFloor;

    public function testAMirrorIsHeldBelowEachConfiguredFloorAndMadeOnceSpaceIsBack(): void
    {
        $this->space->leave($this->bytesFloor - 1, $this->inodesFloor);
        $bytes = $this->console('intakt:mirror:update', ['project' => $this->project, 'remote' => $this->remote->path]);
        $this->space->leave($this->bytesFloor, $this->inodesFloor - 1);
        $inodes = $this->console('intakt:mirror:update', ['project' => $this->project, 'remote' => $this->remote->path]);
        self::assertFileDoesNotExist($this->mirror().'/HEAD');

        $this->space->leave($this->bytesFloor, $this->inodesFloor);
        $resumed = $this->console('intakt:mirror:update', ['project' => $this->project, 'remote' => $this->remote->path]);

        self::assertSame([Command::FAILURE, Command::FAILURE, Command::SUCCESS], [$bytes->getStatusCode(), $inodes->getStatusCode(), $resumed->getStatusCode()]);
        self::assertStringContainsString(sprintf('newworkisheld:%dfreebytesin', $this->bytesFloor - 1), $this->flat($bytes));
        self::assertStringContainsString(sprintf(',belowthefloorof%d', $this->bytesFloor), $this->flat($bytes));
        self::assertStringContainsString(sprintf('newworkisheld:%dfreeinodesin', $this->inodesFloor - 1), $this->flat($inodes));
        self::assertStringContainsString(sprintf(',belowthefloorof%d', $this->inodesFloor), $this->flat($inodes));
        self::assertFileExists($this->mirror().'/HEAD');
    }

    public function testABuildCloneIsHeldBelowTheConfiguredBytesFloorAndMadeOnceSpaceIsBack(): void
    {
        $this->console('intakt:mirror:update', ['project' => $this->project, 'remote' => $this->remote->path]);
        $this->space->leave($this->bytesFloor - 1, $this->inodesFloor);
        $held = $this->console('intakt:build:clone', ['project' => $this->project, 'branch' => 'feat/task', 'directory' => $this->clone]);
        self::assertDirectoryDoesNotExist($this->clone);

        $this->space->leave($this->bytesFloor, $this->inodesFloor);
        $resumed = $this->console('intakt:build:clone', ['project' => $this->project, 'branch' => 'feat/task', 'directory' => $this->clone]);

        self::assertSame([Command::FAILURE, Command::SUCCESS], [$held->getStatusCode(), $resumed->getStatusCode()]);
        self::assertStringContainsString(sprintf('newworkisheld:%dfreebytesin%s,belowthefloorof%d', $this->bytesFloor - 1, dirname($this->clone), $this->bytesFloor), $this->flat($held));
        self::assertDirectoryExists($this->clone.'/.git');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $bytesFloor = $container->getParameter(DiskServices::FREE_BYTES_FLOOR);
        $inodesFloor = $container->getParameter(DiskServices::FREE_INODES_FLOOR);
        $watched = $container->getParameter(DiskServices::WATCHED_DIRECTORY);
        $this->bytesFloor = $bytesFloor;
        $this->inodesFloor = $inodesFloor;
        $this->space = new FreeSpaceInMemory($bytesFloor, $inodesFloor);
        $container->set(FloorCheck::class, new FloorCheck($this->space, $watched, $bytesFloor, $inodesFloor));
        // A scratch folder, so the suite writes nothing into the install's own mirrors.
        $this->mirrors = sys_get_temp_dir().'/intakt-disk-mirrors-'.bin2hex(random_bytes(6));
        $container->set(Mirrors::class, new Mirrors($this->mirrors));
        $this->remote = new TemporaryRepository();
        $this->remote->commitFile('a.txt', 'one');
        $this->project = 'disk-floors-'.bin2hex(random_bytes(4));
        $this->clone = sys_get_temp_dir().'/intakt-disk-clone-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove([$this->mirrors, $this->clone]);
        $this->remote->remove();
        parent::tearDown();
    }

    /**
     * @param array<string, string> $input
     */
    private function console(string $command, array $input): CommandTester
    {
        $kernel = self::$kernel ?? self::fail('The kernel is not booted.');
        $tester = new CommandTester(new Application($kernel)->find($command));
        $tester->execute($input);

        return $tester;
    }

    private function mirror(): string
    {
        return $this->mirrors.'/'.$this->project.'.git';
    }

    // A narrow terminal also breaks a path inside a word, so the comparison drops all whitespace.
    private function flat(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', '', $tester->getDisplay());
    }
}
