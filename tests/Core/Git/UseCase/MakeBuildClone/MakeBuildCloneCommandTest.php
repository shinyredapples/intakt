<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\MakeBuildClone;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\NetworkGit;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\Domain\ProjectNameRefused;
use Intakt\Core\Git\Domain\SourceLine;
use Intakt\Core\Git\Domain\SourceLineNotUnique;
use Intakt\Core\Git\Domain\TaskBranch;
use Intakt\Core\Git\UseCase\MakeBuildClone\BranchNameRefused;
use Intakt\Core\Git\UseCase\MakeBuildClone\BuildCloneNotMade;
use Intakt\Core\Git\UseCase\MakeBuildClone\MakeBuildClone;
use Intakt\Core\Git\UseCase\MakeBuildClone\MakeBuildCloneCommand;
use Intakt\Core\Git\UseCase\MakeBuildClone\MakeBuildCloneInput;
use Intakt\Core\Git\UseCase\UpdateMirror\UpdateMirror;
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
use Symfony\Component\Process\Process;

#[CoversClass(MakeBuildCloneCommand::class)]
#[UsesClass(MakeBuildClone::class)]
#[UsesClass(MakeBuildCloneInput::class)]
#[UsesClass(BuildCloneNotMade::class)]
#[UsesClass(BranchNameRefused::class)]
#[UsesClass(Mirrors::class)]
#[UsesClass(ProjectNameRefused::class)]
#[UsesClass(SourceLine::class)]
#[UsesClass(SourceLineNotUnique::class)]
#[UsesClass(TaskBranch::class)]
#[UsesClass(UpdateMirror::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(NetworkGit::class)]
#[UsesClass(GitCall::class)]
final class MakeBuildCloneCommandTest extends TestCase
{
    private string $mirrors;

    private string $clone;

    private TemporaryRepository $remote;

    public function testTheCommandPrintsThePathOfTheBuildCloneAndExitsWithZero(): void
    {
        $this->givenTheProjectHasAMirror();

        $tester = $this->whenTheCommandRuns('alpha', 'feat/task');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('clone '.$this->clone, $tester->getDisplay());
    }

    public function testTheCommandPrintsADirectoryWithFormattingTagsLiterally(): void
    {
        $this->clone = $this->mirrors.'-clone-<info>literal</info>';
        $this->givenTheProjectHasAMirror();

        $tester = $this->whenTheCommandRuns('alpha', 'feat/task');

        self::assertDirectoryExists($this->clone.'/.git');
        self::assertStringContainsString('clone '.$this->clone, $tester->getDisplay());
    }

    public function testTheCommandPrintsADirectoryWithFormattingTagsLiterallyInTheReason(): void
    {
        $this->clone = $this->mirrors.'-clone-<info>literal</info>';
        new Filesystem()->mkdir($this->clone);
        $this->givenTheProjectHasAMirror();

        $tester = $this->whenTheCommandRuns('alpha', 'feat/task');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        // A narrow terminal also breaks the path inside a word, so the comparison drops all whitespace.
        self::assertStringContainsString('-clone-<info>literal</info>wasnotmade', (string) preg_replace('/\s+/', '', $tester->getDisplay()));
    }

    public function testTheCommandPrintsTheReasonForAProjectWithoutAMirrorAndExitsWithOne(): void
    {
        $tester = $this->whenTheCommandRuns('alpha', 'feat/task');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('has no mirror yet', $this->displayOnOneLine($tester));
    }

    public function testTheCommandPrintsTheReasonForARefusedProjectNameAndExitsWithOne(): void
    {
        $tester = $this->whenTheCommandRuns('../alpha', 'feat/task');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('is not a project name that can be a folder name', $this->displayOnOneLine($tester));
    }

    public function testASourceLineOfATrustedAuthorAddsTheSourceRefToTheClone(): void
    {
        $source = $this->givenTheSourceBranch();
        $this->givenTheProjectHasAMirror();
        $task = $this->givenATaskFile("Part 2.\n\nSource: feat/big@".$source."\n");

        $tester = $this->whenTheCommandRuns('alpha', 'feat/task', ['--task' => $task, '--trusted-author' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringNotContainsString('comment', $tester->getDisplay());
        self::assertSame($source, $this->refOfTheClone('refs/source/feat/big'));
    }

    public function testASourceLineOfAnUntrustedAuthorIsIgnoredWithOneComment(): void
    {
        $source = $this->givenTheSourceBranch();
        $this->givenTheProjectHasAMirror();
        $task = $this->givenATaskFile('Source: feat/big@'.$source."\nSource: feat/other@".$source."\n");

        $tester = $this->whenTheCommandRuns('alpha', 'feat/task', ['--task' => $task]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(1, substr_count($tester->getDisplay(), 'comment '));
        self::assertStringContainsString('comment '.MakeBuildCloneCommand::SOURCE_IGNORED, $tester->getDisplay());
        self::assertSame('', $this->refOfTheClone('refs/source/feat/big'));
    }

    public function testATaskOfAnUntrustedAuthorWithoutASourceLineGetsNoComment(): void
    {
        $this->givenTheProjectHasAMirror();
        $task = $this->givenATaskFile("Add a Source: line later.\n");

        $tester = $this->whenTheCommandRuns('alpha', 'feat/task', ['--task' => $task]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringNotContainsString('comment', $tester->getDisplay());
    }

    public function testATrustedSourceBranchTheMirrorDoesNotHoldExitsWithOneAndMakesNoClone(): void
    {
        $this->givenTheProjectHasAMirror();
        $task = $this->givenATaskFile('Source: feat/other@'.$this->remote->objectAt('main'));

        $tester = $this->whenTheCommandRuns('alpha', 'feat/task', ['--task' => $task, '--trusted-author' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('the mirror holds no branch feat/other', $this->displayOnOneLine($tester));
        self::assertDirectoryDoesNotExist($this->clone);
    }

    public function testATrustedTaskWithTwoSourceLinesExitsWithOneAndMakesNoClone(): void
    {
        $source = $this->givenTheSourceBranch();
        $this->givenTheProjectHasAMirror();
        $task = $this->givenATaskFile('Source: feat/big@'.$source."\nSource: feat/big@".$source);

        $tester = $this->whenTheCommandRuns('alpha', 'feat/task', ['--task' => $task, '--trusted-author' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('more than one Source line', $this->displayOnOneLine($tester));
        self::assertDirectoryDoesNotExist($this->clone);
    }

    public function testATaskFileThatCannotBeReadExitsWithOneAndMakesNoClone(): void
    {
        $this->givenTheProjectHasAMirror();

        $tester = $this->whenTheCommandRuns('alpha', 'feat/task', ['--task' => $this->mirrors.'-missing-task.md', '--trusted-author' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('-missing-task.md', $this->displayOnOneLine($tester));
        self::assertDirectoryDoesNotExist($this->clone);
    }

    public function testATaskFileThatIsAFolderExitsWithOneAndMakesNoClone(): void
    {
        $this->givenTheProjectHasAMirror();

        $tester = $this->whenTheCommandRuns('alpha', 'feat/task', ['--task' => $this->mirrors]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertDirectoryDoesNotExist($this->clone);
    }

    public function testATaskBranchThatIsMainExitsWithOneAndSaysWhy(): void
    {
        $this->givenTheProjectHasAMirror();

        $tester = $this->whenTheCommandRuns('alpha', 'main');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('the task\'s branch "main" is main itself', $this->displayOnOneLine($tester));
    }

    public function testAnUnexpectedInvalidArgumentExceptionFromReadingTheTaskPassesThrough(): void
    {
        $filesystem = self::createStub(Filesystem::class);
        $filesystem->method('readFile')->willThrowException(new InvalidArgumentException('unexpected'));

        $this->expectExceptionObject(new InvalidArgumentException('unexpected'));

        $this->commandReadingTasksWith($filesystem)->execute(['project' => 'alpha', 'branch' => 'feat/task', 'directory' => $this->clone, '--task' => $this->mirrors.'-task.md']);
    }

    protected function setUp(): void
    {
        $this->mirrors = sys_get_temp_dir().'/intakt-mirrors-'.bin2hex(random_bytes(6));
        $this->clone = $this->mirrors.'-clone';
        $this->remote = new TemporaryRepository();
        $this->remote->commitFile('a.txt', 'one');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove([$this->mirrors, $this->mirrors.'-clone', $this->mirrors.'-clone-<info>literal<', $this->mirrors.'-task.md']);
        $this->remote->remove();
    }

    private function givenTheSourceBranch(): string
    {
        $this->remote->checkoutNewBranch('feat/big');
        $source = $this->remote->commitFile('s.txt', 'source');
        $this->remote->checkout('main');

        return $source;
    }

    private function givenATaskFile(string $text): string
    {
        $path = $this->mirrors.'-task.md';
        file_put_contents($path, $text);

        return $path;
    }

    private function refOfTheClone(string $ref): string
    {
        $process = new Process(['git', 'for-each-ref', '--format=%(objectname)', $ref], $this->clone, ['GIT_DIR' => false, 'GIT_WORK_TREE' => false]);
        $process->mustRun();

        return trim($process->getOutput());
    }

    private function givenTheProjectHasAMirror(): void
    {
        $network = new NetworkGit(new RecordingClock(), new NullLogger());
        new UpdateMirror(new HardenedGit(), $network, new Filesystem(), new LockFactory(new InMemoryStore()), new Mirrors($this->mirrors))->update('alpha', $this->remote->path);
    }

    private function displayOnOneLine(CommandTester $tester): string
    {
        // The error block wraps at the terminal width, so where it breaks depends on the temporary path's length.
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function whenTheCommandRuns(string $project, string $branch, array $options = []): CommandTester
    {
        $tester = $this->commandReadingTasksWith(new Filesystem());
        $tester->execute(['project' => $project, 'branch' => $branch, 'directory' => $this->clone, ...$options]);

        return $tester;
    }

    private function commandReadingTasksWith(Filesystem $taskFiles): CommandTester
    {
        $application = new Application();
        $application->addCommand(new MakeBuildCloneCommand(new MakeBuildClone(new HardenedGit(), new Filesystem(), new Mirrors($this->mirrors)), $taskFiles));

        return new CommandTester($application->find('intakt:build:clone'));
    }
}
