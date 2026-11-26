<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\MakeBuildClone;

use Intakt\Core\Disk\Contract\DiskFloor;
use Intakt\Core\Disk\Contract\DiskHeld;
use Intakt\Core\Disk\Contract\NewWorkHeld;
use Intakt\Core\Disk\Domain\FloorCheck;
use Intakt\Tests\Core\Disk\DiskWithRoom;
use Intakt\Tests\Core\Disk\FreeSpaceInMemory;
use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\NetworkGit;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\Domain\ProjectNameRefused;
use Intakt\Core\Git\Domain\SourceLine;
use Intakt\Core\Git\Domain\TaskBranch;
use Intakt\Core\Git\UseCase\MakeBuildClone\BranchNameRefused;
use Intakt\Core\Git\UseCase\MakeBuildClone\BuildCloneNotMade;
use Intakt\Core\Git\UseCase\MakeBuildClone\MakeBuildClone;
use Intakt\Core\Git\UseCase\UpdateMirror\UpdateMirror;
use Intakt\Tests\Core\Git\RecordingClock;
use Intakt\Tests\Core\Git\TemporaryRepository;
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
#[CoversClass(BranchNameRefused::class)]
#[UsesClass(Mirrors::class)]
#[UsesClass(ProjectNameRefused::class)]
#[UsesClass(SourceLine::class)]
#[UsesClass(TaskBranch::class)]
#[UsesClass(UpdateMirror::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(NetworkGit::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(FloorCheck::class)]
#[UsesClass(DiskHeld::class)]
#[UsesClass(NewWorkHeld::class)]
final class MakeBuildCloneTest extends TestCase
{
    private string $mirrors;

    private string $clone;

    private TemporaryRepository $remote;

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

    public function testACloneBelowTheBytesFloorIsHeldAndMadeOnceSpaceIsBack(): void
    {
        $this->givenTheMirror();
        $space = new FreeSpaceInMemory(10, 10 ** 6);
        $clones = new MakeBuildClone(new HardenedGit(), new Filesystem(), new Mirrors($this->mirrors), new FloorCheck($space, '/unused', 1000, 100));

        try {
            $clones->make('alpha', new TaskBranch('feat/task'), $this->clone);
            self::fail('The clone was made below the bytes floor.');
        } catch (NewWorkHeld $held) {
            self::assertSame(DiskFloor::Bytes, $held->held->floor);
            self::assertDirectoryDoesNotExist($this->clone);
        }

        $space->leave(5000, 10 ** 6);
        $clones->make('alpha', new TaskBranch('feat/task'), $this->clone);
        self::assertDirectoryExists($this->clone.'/.git');
    }

    public function testACloneBelowTheInodesFloorIsHeldThoughBytesArePlenty(): void
    {
        $this->givenTheMirror();
        $clones = new MakeBuildClone(new HardenedGit(), new Filesystem(), new Mirrors($this->mirrors), new FloorCheck(new FreeSpaceInMemory(10 ** 12, 99), '/unused', 1000, 100));

        $this->expectExceptionObject(new NewWorkHeld(new DiskHeld(DiskFloor::Inodes, dirname($this->clone), 99, 100)));

        $clones->make('alpha', new TaskBranch('feat/task'), $this->clone);
    }

    public function testTheFreeSpaceIsReadWhereTheCloneLandsNotInTheWatchedFolder(): void
    {
        $this->givenTheMirror();
        $space = new FreeSpaceInMemory(5000, 500);
        $clone = $this->clone.'/not/yet/made';

        new MakeBuildClone(new HardenedGit(), new Filesystem(), new Mirrors($this->mirrors), new FloorCheck($space, '/unused', 1000, 100))->make('alpha', new TaskBranch('feat/task'), $clone);

        self::assertSame([dirname($this->clone)], array_values(array_unique($space->asked)));
    }

    public function testAProjectNameThatIsNotASafePathSegmentIsRefused(): void
    {
        $this->expectException(ProjectNameRefused::class);

        $this->buildClones()->make('../alpha', new TaskBranch('feat/task'), $this->clone);
    }

    #[DataProvider('unsafeBranches')]
    public function testABranchNameGitWouldRefuseOrReadAsSomethingElseIsRefused(string $branch): void
    {
        $this->givenTheMirror();

        $this->expectException(BranchNameRefused::class);

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

        $this->expectExceptionObject(new BranchNameRefused('the task\'s branch "main" is main itself'));

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

    public function testACloneThatTakesTheFolderFirstKeepsItAndTheLaterCallIsRefused(): void
    {
        $winner = $this->givenABranch('feat/winner', 'w.txt');
        $main = $this->remote->objectAt('main');
        $this->givenTheMirror();
        $racing = new FilesystemThatLetsAnotherCloneInFirst($this->buildClones(), 'feat/winner', $this->clone);

        try {
            new MakeBuildClone(new HardenedGit(), $racing, new Mirrors($this->mirrors), new DiskWithRoom())->make('alpha', new TaskBranch('feat/loser'), $this->clone);
            self::fail('the build clone was made in a folder another clone already owns');
        } catch (BuildCloneNotMade $refused) {
            self::assertStringContainsString('the folder already exists', $refused->getMessage());
        }

        self::assertSame([$winner.' refs/heads/feat/winner', $main.' refs/heads/main'], $this->refsOfTheClone());
    }

    public function testAFolderThatIsADanglingLinkIsRefusedAndLeftAlone(): void
    {
        $this->givenTheMirror();
        symlink($this->mirrors.'-nowhere', $this->clone);

        try {
            $this->whenTheBuildCloneIsMade('feat/task');
            self::fail('the build clone was made through a link');
        } catch (BuildCloneNotMade $refused) {
            self::assertStringContainsString('the folder already exists', $refused->getMessage());
        }

        self::assertTrue(is_link($this->clone));
        self::assertFileDoesNotExist($this->mirrors.'-nowhere');
    }

    public function testAMirrorWhoseHeadNamesNoBranchIsRefused(): void
    {
        $this->remote->checkoutDetached('main');
        $this->remote->commitEmpty('On no branch');
        $this->givenTheMirror();

        $this->expectExceptionObject(BuildCloneNotMade::at($this->clone, 'the mirror\'s HEAD names no branch, so it has no main'));

        $this->whenTheBuildCloneIsMade('feat/task');
    }

    public function testAMirrorWhoseHeadNamesATagIsRefused(): void
    {
        $this->remote->tag('v1');
        $mirror = $this->givenTheMirror();
        $this->git($mirror, '--git-dir='.$mirror, 'symbolic-ref', 'HEAD', 'refs/tags/v1');

        $this->expectExceptionObject(BuildCloneNotMade::at($this->clone, 'the mirror\'s HEAD names no branch, so it has no main'));

        try {
            $this->whenTheBuildCloneIsMade('feat/task');
        } finally {
            self::assertDirectoryDoesNotExist($this->clone);
        }
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

    public function testATrustedSourceAddsItsCommitAsARefBesideMainAndTheTasksBranch(): void
    {
        $task = $this->givenABranch('feat/task', 'b.txt');
        $source = $this->givenABranch('feat/big', 's.txt');
        $main = $this->remote->objectAt('main');
        $this->givenTheMirror();

        $this->whenTheBuildCloneIsMadeWithTheSource('feat/task', 'feat/big', $source);

        self::assertSame([$task.' refs/heads/feat/task', $main.' refs/heads/main', $source.' refs/source/feat/big'], $this->refsOfTheClone());
        self::assertSame('refs/heads/feat/task', $this->inTheClone('symbolic-ref', 'HEAD'));
    }

    public function testTheCloneResolvesTheSourceRefAtTheVerifiedCommitOnlyAndHoldsNoLaterCommitOfItsBranch(): void
    {
        $source = $this->givenABranch('feat/big', 's.txt');
        $this->remote->checkout('feat/big');
        $later = $this->remote->commitFile('later.txt', 'later');
        $this->remote->checkout('main');
        $this->givenTheMirror();

        $this->whenTheBuildCloneIsMadeWithTheSource('feat/task', 'feat/big', $source);

        self::assertSame($source, $this->inTheClone('rev-parse', 'refs/source/feat/big'));
        self::assertFalse($this->git($this->clone, 'cat-file', '-e', $later.'^{commit}')->isSuccessful());
    }

    public function testASourceOfASha256MirrorIsResolvedAtItsCommit(): void
    {
        $this->remote->remove();
        $this->remote = new TemporaryRepository('sha256');
        $this->remote->commitFile('a.txt', 'one');
        $source = $this->givenABranch('feat/big', 's.txt');
        $this->givenTheMirror();

        $this->whenTheBuildCloneIsMadeWithTheSource('feat/task', 'feat/big', $source);

        self::assertSame($source, $this->inTheClone('rev-parse', 'refs/source/feat/big'));
    }

    public function testASourceCommitThatIsNotOnItsBranchIsRefusedAndNoCloneFolderIsLeft(): void
    {
        $this->givenABranch('feat/big', 's.txt');
        $other = $this->givenABranch('other', 'c.txt');
        $this->givenTheMirror();

        $this->expectExceptionObject(BuildCloneNotMade::at($this->clone, sprintf('the commit %s is not on the branch feat/big', $other)));

        try {
            $this->whenTheBuildCloneIsMadeWithTheSource('feat/task', 'feat/big', $other);
        } finally {
            self::assertDirectoryDoesNotExist($this->clone);
        }
    }

    public function testASourceCommitTheMirrorDoesNotHoldIsRefused(): void
    {
        $this->givenABranch('feat/big', 's.txt');
        $this->givenTheMirror();
        $unknown = str_repeat('0', 39).'1';

        $this->expectExceptionObject(BuildCloneNotMade::at($this->clone, sprintf('the commit %s is not on the branch feat/big', $unknown)));

        $this->whenTheBuildCloneIsMadeWithTheSource('feat/task', 'feat/big', $unknown);
    }

    public function testAnAnnotatedTagOnTheSourceBranchIsNoCommitAndIsRefusedAndNoCloneFolderIsLeft(): void
    {
        $this->givenABranch('feat/big', 's.txt');
        $tag = $this->remote->annotatedTag('v1', 'feat/big');
        $this->givenTheMirror();

        $this->expectExceptionObject(BuildCloneNotMade::at($this->clone, sprintf('the object %s is not a commit', $tag)));

        try {
            $this->whenTheBuildCloneIsMadeWithTheSource('feat/task', 'feat/big', $tag);
        } finally {
            self::assertDirectoryDoesNotExist($this->clone);
        }
    }

    public function testASourceBranchTheMirrorDoesNotHoldIsRefused(): void
    {
        $main = $this->remote->objectAt('main');
        $this->givenTheMirror();

        $this->expectExceptionObject(BuildCloneNotMade::at($this->clone, 'the mirror holds no branch feat/gone to take the source from'));

        try {
            $this->whenTheBuildCloneIsMadeWithTheSource('feat/task', 'feat/gone', $main);
        } finally {
            self::assertDirectoryDoesNotExist($this->clone);
        }
    }

    #[DataProvider('unsafeSourceBranches')]
    public function testAnUnsafeSourceBranchIsAnErrorAndNoCloneFolderIsLeft(string $branch): void
    {
        $main = $this->remote->objectAt('main');
        $this->givenTheMirror();

        $this->expectExceptionObject(new BranchNameRefused(sprintf('"%s" is not a branch name git accepts', $branch)));

        try {
            $this->whenTheBuildCloneIsMadeWithTheSource('feat/task', $branch, $main);
        } finally {
            self::assertDirectoryDoesNotExist($this->clone);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeSourceBranches(): iterable
    {
        yield 'an option' => ['--upload-pack=touch'];
        yield 'HEAD' => ['HEAD'];
        yield 'two dots' => ['a..b'];
        yield 'a previous branch' => ['@{-1}'];
        yield 'a lock file' => ['x.lock'];
        yield 'a leading slash' => ['/main'];
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
        new Filesystem()->remove([$this->mirrors, $this->clone]);
        $this->remote->remove();
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
        $this->buildClones()->make('alpha', new TaskBranch($branch), $this->clone);
    }

    private function whenTheBuildCloneIsMadeWithTheSource(string $branch, string $sourceBranch, string $commit): void
    {
        $source = SourceLine::in(sprintf('Source: %s@%s', $sourceBranch, $commit));
        self::assertNotNull($source);

        $this->buildClones()->make('alpha', new TaskBranch($branch, $source), $this->clone);
    }

    private function buildClones(): MakeBuildClone
    {
        return new MakeBuildClone(new HardenedGit(), new Filesystem(), new Mirrors($this->mirrors), new DiskWithRoom());
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
