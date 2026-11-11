<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\MakeBuildClone;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\NetworkGit;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\UseCase\MakeBuildClone\BuildCloneNotMade;
use Intakt\Core\Git\UseCase\MakeBuildClone\MakeBuildClone;
use Intakt\Core\Git\UseCase\UpdateMirror\UpdateMirror;
use Intakt\Tests\Core\Git\RecordingClock;
use Intakt\Tests\Core\Git\TemporaryRepository;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Process\Process;

#[CoversClass(MakeBuildClone::class)]
#[CoversClass(BuildCloneNotMade::class)]
#[UsesClass(Mirrors::class)]
#[UsesClass(UpdateMirror::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(NetworkGit::class)]
#[UsesClass(GitCall::class)]
final class MakeBuildCloneTest extends TestCase
{
    private string $mirrors;

    private string $clone;

    private TemporaryRepository $remote;

    protected function setUp(): void
    {
        $this->mirrors = sys_get_temp_dir().'/intakt-mirrors-'.bin2hex(random_bytes(6));
        $this->clone = $this->mirrors.'-clone';
        $this->remote = new TemporaryRepository();
        $this->remote->commitFile('a.txt', 'one');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove([$this->mirrors, $this->clone]);
        $this->remote->remove();
    }

    public function testTheBuildCloneHoldsMainAndTheTasksBranchAndNoOtherRef(): void
    {
        $task = $this->givenABranch('feat/task', 'b.txt');
        $this->givenABranch('other', 'c.txt');
        $this->remote->tag('v1');
        $main = $this->remote->objectAt('main');
        $this->givenTheMirror();

        $this->whenTheBuildCloneIsMade('feat/task');

        self::assertSame([$task.' refs/heads/feat/task', $main.' refs/heads/main'], $this->refsOfTheClone());
    }

    public function testTheBuildCloneChecksOutTheTasksBranch(): void
    {
        $this->givenABranch('feat/task', 'b.txt');
        $this->givenTheMirror();

        $this->whenTheBuildCloneIsMade('feat/task');

        self::assertSame('refs/heads/feat/task', $this->inTheClone('symbolic-ref', 'HEAD'));
        self::assertSame('b.txt', file_get_contents($this->clone.'/b.txt'));
        self::assertSame('', $this->inTheClone('status', '--porcelain'));
    }

    public function testABranchTheMirrorDoesNotHoldYetStartsAtMain(): void
    {
        $main = $this->remote->objectAt('main');
        $this->givenTheMirror();

        $this->whenTheBuildCloneIsMade('feat/new');

        self::assertSame([$main.' refs/heads/feat/new', $main.' refs/heads/main'], $this->refsOfTheClone());
        self::assertSame('refs/heads/feat/new', $this->inTheClone('symbolic-ref', 'HEAD'));
    }

    public function testTheBuildCloneHoldsNoCommitOfAnotherBranch(): void
    {
        $other = $this->givenABranch('other', 'c.txt');
        $this->givenTheMirror();

        $this->whenTheBuildCloneIsMade('feat/task');

        self::assertFalse($this->git($this->clone, 'cat-file', '-e', $other.'^{commit}')->isSuccessful());
    }

    public function testTheBuildCloneNamesNoRemoteAndLeavesNoFetchHead(): void
    {
        $this->givenABranch('feat/task', 'b.txt');
        $this->givenTheMirror();

        $this->whenTheBuildCloneIsMade('feat/task');

        self::assertFalse($this->git($this->clone, 'config', '--get-regexp', '^remote\.')->isSuccessful());
        self::assertFileDoesNotExist($this->clone.'/.git/FETCH_HEAD');
        self::assertFileDoesNotExist($this->clone.'/.git/ORIG_HEAD');
    }

    public function testMainIsTheBranchTheMirrorsHeadNames(): void
    {
        $this->remote->checkoutNewBranch('trunk');
        $trunk = $this->remote->commitFile('t.txt', 'trunk');
        $this->givenTheMirror();

        $this->whenTheBuildCloneIsMade('feat/task');

        self::assertSame([$trunk.' refs/heads/feat/task', $trunk.' refs/heads/trunk'], $this->refsOfTheClone());
    }

    public function testTheBuildCloneOfASha256MirrorIsASha256RepositoryAtTheSameCommits(): void
    {
        $this->remote->remove();
        $this->remote = new TemporaryRepository('sha256');
        $this->remote->commitFile('a.txt', 'one');
        $task = $this->givenABranch('feat/task', 'b.txt');
        $main = $this->remote->objectAt('main');
        $this->givenTheMirror();

        $this->whenTheBuildCloneIsMade('feat/task');

        self::assertSame('sha256', $this->inTheClone('rev-parse', '--show-object-format'));
        self::assertSame([$task.' refs/heads/feat/task', $main.' refs/heads/main'], $this->refsOfTheClone());
    }

    public function testAProjectWithoutAMirrorIsRefusedAndNoCloneFolderIsLeft(): void
    {
        $this->expectExceptionObject(BuildCloneNotMade::at($this->clone, 'the project alpha has no mirror yet'));

        try {
            $this->whenTheBuildCloneIsMade('feat/task');
        } finally {
            self::assertDirectoryDoesNotExist($this->clone);
        }
    }

    public function testAProjectNameThatIsNotASafePathSegmentIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->buildClones()->make('../alpha', 'feat/task', $this->clone);
    }

    #[DataProvider('unsafeBranches')]
    public function testABranchNameGitWouldRefuseOrReadAsSomethingElseIsRefused(string $branch): void
    {
        $this->givenTheMirror();

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->whenTheBuildCloneIsMade($branch);
        } finally {
            self::assertDirectoryDoesNotExist($this->clone);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeBranches(): iterable
    {
        yield 'empty' => [''];
        yield 'an option' => ['-x'];
        yield 'HEAD' => ['HEAD'];
        yield 'two dots' => ['a..b'];
        yield 'a previous branch' => ['@{-1}'];
        yield 'a space' => ['a b'];
        yield 'a lock file' => ['x.lock'];
    }

    public function testTheTasksBranchCannotBeMain(): void
    {
        $this->givenTheMirror();

        $this->expectExceptionObject(new InvalidArgumentException('the task\'s branch "main" is main itself'));

        $this->whenTheBuildCloneIsMade('main');
    }

    public function testAFolderThatAlreadyExistsIsRefusedAndLeftAlone(): void
    {
        $this->givenTheMirror();
        mkdir($this->clone);
        file_put_contents($this->clone.'/keep.txt', 'kept');

        try {
            $this->whenTheBuildCloneIsMade('feat/task');
            self::fail('the build clone was made in a folder that already existed');
        } catch (BuildCloneNotMade $refused) {
            self::assertStringContainsString('the folder already exists', $refused->getMessage());
        }

        self::assertSame('kept', file_get_contents($this->clone.'/keep.txt'));
    }

    public function testAMirrorWhoseHeadNamesNoBranchIsRefused(): void
    {
        $this->remote->checkoutDetached('main');
        $this->remote->commitEmpty('On no branch');
        $this->givenTheMirror();

        $this->expectExceptionObject(BuildCloneNotMade::at($this->clone, 'the mirror\'s HEAD names no branch, so it has no main'));

        $this->whenTheBuildCloneIsMade('feat/task');
    }

    public function testAFetchThatFailsLeavesNoCloneFolderBehind(): void
    {
        $mirror = $this->givenTheMirror();
        $this->git($mirror, '--git-dir='.$mirror, 'symbolic-ref', 'HEAD', 'refs/heads/gone');

        try {
            $this->whenTheBuildCloneIsMade('feat/task');
            self::fail('the build clone was made from a mirror without main');
        } catch (BuildCloneNotMade $failed) {
            self::assertStringContainsString('refs/heads/gone', $failed->getMessage());
        }

        self::assertDirectoryDoesNotExist($this->clone);
    }

    private function givenABranch(string $name, string $file): string
    {
        $this->remote->checkoutNewBranch($name);
        $commit = $this->remote->commitFile($file, $file);
        $this->remote->checkout('main');

        return $commit;
    }

    private function givenTheMirror(): string
    {
        $network = new NetworkGit(new RecordingClock(), new NullLogger());

        return new UpdateMirror(new HardenedGit(), $network, new Filesystem(), new LockFactory(new InMemoryStore()), new Mirrors($this->mirrors))->update('alpha', $this->remote->path);
    }

    private function whenTheBuildCloneIsMade(string $branch): void
    {
        $this->buildClones()->make('alpha', $branch, $this->clone);
    }

    private function buildClones(): MakeBuildClone
    {
        return new MakeBuildClone(new HardenedGit(), new Filesystem(), new Mirrors($this->mirrors));
    }

    /**
     * @return list<string>
     */
    private function refsOfTheClone(): array
    {
        return explode("\n", $this->inTheClone('for-each-ref', '--format=%(objectname) %(refname)'));
    }

    private function inTheClone(string ...$arguments): string
    {
        $process = $this->git($this->clone, ...$arguments);

        if (!$process->isSuccessful()) {
            throw new RuntimeException($process->getErrorOutput());
        }

        return trim($process->getOutput());
    }

    private function git(string $directory, string ...$arguments): Process
    {
        $process = new Process(['git', ...array_values($arguments)], $directory, ['GIT_DIR' => false, 'GIT_WORK_TREE' => false]);
        $process->run();

        return $process;
    }
}
