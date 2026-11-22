<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\ListProtectedChanges;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\ProtectedChanges;
use Intakt\Core\Git\Domain\ChangedPath;
use Intakt\Core\Git\Domain\ChangeNotRead;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\Domain\ProjectNameRefused;
use Intakt\Core\Git\Domain\ProtectedPathRefused;
use Intakt\Core\Git\Domain\ProtectedPaths;
use Intakt\Core\Git\UseCase\ListProtectedChanges\ChangedPaths;
use Intakt\Core\Git\UseCase\ListProtectedChanges\CommitNameRefused;
use Intakt\Core\Git\UseCase\ListProtectedChanges\ListProtectedChanges;
use Intakt\Core\Git\UseCase\ListProtectedChanges\ListProtectedChangesCommand;
use Intakt\Core\Git\UseCase\ListProtectedChanges\ProtectedHeadsOfMirrors;
use Intakt\Tests\Core\Git\PregMatchThatThrows;
use Intakt\Tests\Core\Git\TemporaryRepository;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(ListProtectedChangesCommand::class)]
#[UsesClass(ListProtectedChanges::class)]
#[UsesClass(ProtectedHeadsOfMirrors::class)]
#[UsesClass(ChangedPaths::class)]
#[UsesClass(ChangedPath::class)]
#[UsesClass(ChangeNotRead::class)]
#[UsesClass(ProtectedChanges::class)]
#[UsesClass(ProtectedPaths::class)]
#[UsesClass(ProtectedPathRefused::class)]
#[UsesClass(CommitNameRefused::class)]
#[UsesClass(Mirrors::class)]
#[UsesClass(ProjectNameRefused::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
final class ListProtectedChangesCommandTest extends TestCase
{
    private string $mirrors;

    private TemporaryRepository $repository;

    protected function setUp(): void
    {
        $this->mirrors = sys_get_temp_dir().'/intakt-mirrors-'.bin2hex(random_bytes(6));
        mkdir($this->mirrors);
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('bin/check', 'one');
        $this->repository->checkoutNewBranch('feat/side');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->mirrors);
        $this->repository->remove();
    }

    public function testTheCommandListsTheProtectedPathsOfTheProjectAndExitsWithZero(): void
    {
        $this->repository->commitFile('bin/check', 'two');
        $head = $this->repository->commitFile("src/a\nprotected b", 'one');

        $tester = $this->whenTheCommandRuns(['alpha' => ['bin/check', 'src']], $head);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("protected bin/check\nprotected src/a\\nprotected b\ntouches protected paths: yes\n", $tester->getDisplay());
    }

    public function testTheCommandSaysWhenTheHeadTouchesNoProtectedPath(): void
    {
        $head = $this->repository->commitFile('bin/check', 'two');

        $tester = $this->whenTheCommandRuns(['beta' => ['bin/check']], $head);

        self::assertSame("touches protected paths: no\n", $tester->getDisplay());
    }

    public function testTheCommandSaysWhyAnUnreadableHeadCountsAsProtected(): void
    {
        $tester = $this->whenTheCommandRuns([], str_repeat('a', 40));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('unreadable the change could not be read: ', $tester->getDisplay());
        self::assertStringEndsWith("touches protected paths: yes\n", $tester->getDisplay());
    }

    #[RunInSeparateProcess]
    public function testAnUnexpectedInvalidArgumentExceptionPassesThrough(): void
    {
        $head = $this->repository->objectAt('HEAD');
        $this->expectExceptionObject(new InvalidArgumentException('unexpected'));

        PregMatchThatThrows::define();
        $this->whenTheCommandRuns([], $head);
    }

    public function testTheCommandRefusesAHeadThatIsNoFullCommitNameAndExitsWithOne(): void
    {
        $tester = $this->whenTheCommandRuns([], 'feat/side');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('"feat/side" is not a full commit name', $tester->getDisplay());
    }

    public function testTheCommandRefusesAProjectNameThatIsNoFolderNameAndExitsWithOne(): void
    {
        $tester = $this->whenTheCommandRuns([], $this->repository->objectAt('HEAD'), '../alpha');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('is not a project name that can be a folder name', $tester->getDisplay());
    }

    public function testTheCommandRefusesAProtectedPathThatIsNoPathOfTheRepositoryAndExitsWithOne(): void
    {
        $tester = $this->whenTheCommandRuns(['alpha' => ['../outside']], $this->repository->objectAt('HEAD'));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('"../outside" is not a protected path', $tester->getDisplay());
    }

    /**
     * @param array<string, list<string>> $protectedPaths
     */
    private function whenTheCommandRuns(array $protectedPaths, string $head, string $project = 'alpha'): CommandTester
    {
        $this->repository->checkout('main');
        symlink($this->repository->path.'/.git', $this->mirrors.'/alpha.git');
        $command = new ListProtectedChangesCommand(new ProtectedHeadsOfMirrors(new ListProtectedChanges(new ChangedPaths(new HardenedGit())), new Mirrors($this->mirrors), $protectedPaths));
        $application = new Application();
        $application->addCommand($command);
        $tester = new CommandTester($application->find('intakt:protected:list'));
        $tester->execute(['project' => $project, 'head' => $head]);

        return $tester;
    }
}
