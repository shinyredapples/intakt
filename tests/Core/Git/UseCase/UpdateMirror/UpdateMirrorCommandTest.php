<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\UpdateMirror;

use Intakt\Core\Disk\Contract\DiskHeadroom;
use Intakt\Core\Disk\Contract\DiskHeld;
use Intakt\Core\Disk\Contract\NewWorkHeld;
use Intakt\Core\Disk\Domain\FloorCheck;
use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\NetworkGit;
use Intakt\Core\Git\Contract\NetworkGitFailed;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\Domain\ProjectNameRefused;
use Intakt\Core\Git\UseCase\UpdateMirror\MirrorNotUpdated;
use Intakt\Core\Git\UseCase\UpdateMirror\UpdateMirror;
use Intakt\Core\Git\UseCase\UpdateMirror\UpdateMirrorCommand;
use Intakt\Tests\Core\Disk\DiskWithRoom;
use Intakt\Tests\Core\Disk\FreeSpaceInMemory;
use Intakt\Tests\Core\Git\RecordingClock;
use Intakt\Tests\Core\Git\TemporaryRepository;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(UpdateMirrorCommand::class)]
#[UsesClass(UpdateMirror::class)]
#[UsesClass(Mirrors::class)]
#[UsesClass(ProjectNameRefused::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(NetworkGit::class)]
#[UsesClass(NetworkGitFailed::class)]
#[UsesClass(MirrorNotUpdated::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(FloorCheck::class)]
#[UsesClass(DiskHeld::class)]
#[UsesClass(NewWorkHeld::class)]
final class UpdateMirrorCommandTest extends TestCase
{
    private string $mirrors;

    private TemporaryRepository $remote;

    public function testTheCommandPrintsThePathOfTheMirrorAndExitsWithZero(): void
    {
        $tester = $this->whenTheCommandRuns('alpha', $this->remote->path);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('mirror '.$this->mirrors.'/alpha.git', $tester->getDisplay());
    }

    public function testTheCommandPrintsTheReasonForARefusedNameAndExitsWithOne(): void
    {
        $tester = $this->whenTheCommandRuns('../alpha', $this->remote->path);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('is not a project name that can be a folder name', $this->displayOnOneLine($tester));
    }

    public function testTheCommandPrintsTheFailureOfAMissingRemoteAndExitsWithOne(): void
    {
        $tester = $this->whenTheCommandRuns('alpha', $this->mirrors.'-missing');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('git failed after 3 attempts', $this->displayOnOneLine($tester));
    }

    public function testTheCommandPrintsAMirrorThatWasNotUpdatedAndExitsWithOne(): void
    {
        $this->whenTheCommandRuns('alpha', $this->remote->path);
        file_put_contents($this->mirrors.'/alpha.git/HEAD.lock', '');

        $tester = $this->whenTheCommandRuns('alpha', $this->remote->path);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('was not updated', $this->displayOnOneLine($tester));
    }

    public function testAnUnexpectedInvalidArgumentExceptionPassesThroughTheCommand(): void
    {
        $filesystem = self::createStub(Filesystem::class);
        $filesystem->method('mkdir')->willThrowException(new InvalidArgumentException('unexpected'));

        $this->expectExceptionObject(new InvalidArgumentException('unexpected'));

        $this->whenTheCommandRuns('alpha', $this->remote->path, $filesystem);
    }

    public function testAMirrorBelowAFloorSaysWhichFloorAndWhereExitsWithOneAndClonesNothing(): void
    {
        $tester = $this->whenTheCommandRuns('alpha', $this->remote->path, disk: new FloorCheck(new FreeSpaceInMemory(10, 10 ** 6), '/unused', 1000, 100));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        // A narrow terminal also breaks the path inside a word, so the comparison drops all whitespace.
        self::assertStringContainsString('newworkisheld:10freebytesin'.dirname($this->mirrors).',belowthefloorof1000', (string) preg_replace('/\s+/', '', $tester->getDisplay()));
        self::assertFileDoesNotExist($this->mirrors.'/alpha.git/HEAD');
    }

    protected function setUp(): void
    {
        $this->mirrors = sys_get_temp_dir().'/intakt-mirrors-'.bin2hex(random_bytes(6));
        $this->remote = new TemporaryRepository();
        $this->remote->commitFile('a.txt', 'one');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->mirrors);
        $this->remote->remove();
    }

    private function displayOnOneLine(CommandTester $tester): string
    {
        // The error block wraps at the terminal width, so where it breaks depends on the temporary path's length.
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function whenTheCommandRuns(string $project, string $remote, Filesystem $filesystem = new Filesystem(), DiskHeadroom $disk = new DiskWithRoom()): CommandTester
    {
        $network = new NetworkGit(new RecordingClock(), new NullLogger());
        $mirror = new UpdateMirror(new HardenedGit(), $network, $filesystem, new LockFactory(new InMemoryStore()), new Mirrors($this->mirrors), $disk);
        $application = new Application();
        $application->addCommand(new UpdateMirrorCommand($mirror));
        $tester = new CommandTester($application->find('intakt:mirror:update'));
        $tester->execute(['project' => $project, 'remote' => $remote]);

        return $tester;
    }
}
