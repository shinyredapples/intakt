<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\ListProtectedChanges;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\ProtectedChanges;
use Intakt\Core\Git\Domain\ChangedPath;
use Intakt\Core\Git\Domain\ChangeNotRead;
use Intakt\Core\Git\Domain\ProtectedPaths;
use Intakt\Core\Git\UseCase\ListProtectedChanges\ChangedPaths;
use Intakt\Core\Git\UseCase\ListProtectedChanges\CommitNameRefused;
use Intakt\Core\Git\UseCase\ListProtectedChanges\ListProtectedChanges;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListProtectedChanges::class)]
#[CoversClass(CommitNameRefused::class)]
#[CoversClass(ChangedPaths::class)]
#[CoversClass(ProtectedChanges::class)]
#[CoversClass(ChangeNotRead::class)]
#[UsesClass(ProtectedPaths::class)]
#[UsesClass(ChangedPath::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
final class ListProtectedChangesTest extends TestCase
{
    private TemporaryRepository $repository;

    private string $base;

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->givenMainWithTheProtectedFiles($this->repository);
    }

    protected function tearDown(): void
    {
        $this->repository->remove();
    }

    public function testAChangeToAProtectedFileIsListed(): void
    {
        $head = $this->givenABranchCommitting('bin/check', 'two');

        $changes = $this->whenTheHeadIsListed($head);

        self::assertSame(['bin/check'], $changes->paths);
        self::assertTrue($changes->touchesProtectedPaths());
    }

    public function testAFileBelowAProtectedFolderIsListed(): void
    {
        $head = $this->givenABranchCommitting('.github/workflows/ci.yml', 'on: push');

        self::assertSame(['.github/workflows/ci.yml'], $this->whenTheHeadIsListed($head)->paths);
    }

    public function testAChangeOutsideTheProtectedPathsListsNothing(): void
    {
        $this->givenABranchCommitting('src/a.php', 'two');
        $this->repository->commitFile('bin/checker', 'one');
        $head = $this->repository->commitFile('config/gates.php', 'one');

        $changes = $this->whenTheHeadIsListed($head);

        self::assertSame([], $changes->paths);
        self::assertFalse($changes->touchesProtectedPaths());
    }

    public function testAProtectedChangeUndoneLaterInTheBranchIsStillListed(): void
    {
        $this->givenABranchCommitting('bin/check', 'two');
        $head = $this->repository->commitFile('bin/check', 'one');

        self::assertSame(['bin/check'], $this->whenTheHeadIsListed($head)->paths);
    }

    public function testARevertOfAProtectedFileInACraftedMergeIsListed(): void
    {
        $this->repository->commitFile('config/gates/rule.php', 'two');
        $this->givenABranchCommitting('src/a.php', 'two');
        $head = $this->repository->craftMerge($this->base, 'config/gates/rule.php', 'one');

        self::assertSame(['config/gates/rule.php'], $this->whenTheHeadIsListed($head)->paths);
    }

    public function testADeletionOfAProtectedFileInACraftedMergeIsListed(): void
    {
        $this->givenABranchCommitting('src/a.php', 'two');
        $head = $this->repository->craftMerge($this->base, 'config/gates/rule.php', null);

        self::assertSame(['config/gates/rule.php'], $this->whenTheHeadIsListed($head)->paths);
    }

    public function testARevertInACraftedMergeThatALaterCommitUndoesIsStillListed(): void
    {
        $this->repository->commitFile('config/gates/rule.php', 'two');
        $this->givenABranchCommitting('src/a.php', 'two');
        $this->repository->craftMerge($this->base, 'config/gates/rule.php', 'one');
        $head = $this->repository->commitFile('config/gates/rule.php', 'two');

        self::assertSame(['config/gates/rule.php'], $this->whenTheHeadIsListed($head)->paths);
    }

    public function testARevertToAnOldMainInACraftedMergeThatASecondCraftedMergeUndoesIsListed(): void
    {
        $this->repository->commitFile('config/gates/rule.php', 'two');
        $this->givenABranchCommitting('src/a.php', 'two');
        $this->repository->craftMerge($this->base, 'config/gates/rule.php', 'one');
        $this->repository->checkout('main');
        $main = $this->repository->commitFile('other.txt', 'one');
        $this->repository->checkout('feat/side');
        $head = $this->repository->craftMerge($main, 'config/gates/rule.php', 'two');

        self::assertSame(['config/gates/rule.php'], $this->whenTheHeadIsListed($head)->paths);
    }

    public function testAProtectedChangeUndoneLaterIsStillListedWhenMainMovesDuringTheCheck(): void
    {
        $changed = $this->givenABranchCommitting('bin/check', 'two');
        $head = $this->repository->commitFile('bin/check', 'one');
        $this->repository->checkout('main');
        $this->repository->checkoutNewBranch('next-main');
        $next = $this->repository->commitFile('bin/check', 'two');
        $this->repository->checkout('main');

        // Main moves to the undone commit's content while that commit is compared with its parent, because a main read again from its branch would then find nothing new in it.
        $changes = $this->whileMainAdvancesTo($next, $this->base.' '.$changed, fn (): ProtectedChanges => $this->whenTheHeadIsListed($head));

        self::assertSame(['bin/check'], $changes->paths);
        self::assertNull($changes->unreadable);
    }

    public function testMainIsTheCommitTheBranchNamesNotTheBranch(): void
    {
        $main = new ChangedPaths(new HardenedGit())->mainOf($this->repository->path.'/.git');

        self::assertSame($this->base, $main);
    }

    public function testARepositoryWhoseMainHasNoCommitCountsAsProtected(): void
    {
        $repository = new TemporaryRepository();

        try {
            $changes = $this->listed($repository->path.'/.git', $this->base);
        } finally {
            $repository->remove();
        }

        self::assertTrue($changes->touchesProtectedPaths());
        self::assertSame('the change could not be read: refs/heads/main names no commit, so main is unknown', $changes->unreadable);
    }

    public function testAProtectedChangeOnAMergedSideBranchIsListed(): void
    {
        $this->givenABranchCommitting('bin/check', 'two');
        $this->repository->commitFile('bin/check', 'one');
        $this->repository->checkout('main');
        $this->givenABranchCommitting('src/a.php', 'two', 'feat/task');
        $head = $this->repository->merge('feat/side');

        self::assertSame(['bin/check'], $this->whenTheHeadIsListed($head)->paths);
    }

    public function testAProtectedChangeThatAMergeOfMainBringsInIsNotListed(): void
    {
        $this->givenABranchCommitting('src/a.php', 'two');
        $this->repository->checkout('main');
        $this->repository->commitFile('bin/check', 'two');
        $this->repository->checkout('feat/side');
        $head = $this->repository->merge('main');

        self::assertSame([], $this->whenTheHeadIsListed($head)->paths);
    }

    public function testAMergeOfMainThatLeavesAProtectedFileUnlikeMainIsListed(): void
    {
        $this->givenABranchCommitting('src/a.php', 'two');
        $this->repository->checkout('main');
        $this->repository->commitFile('bin/check', 'two');
        $this->repository->checkout('feat/side');
        $head = $this->repository->mergeChanging('main', 'bin/check', 'three');

        self::assertSame(['bin/check'], $this->whenTheHeadIsListed($head)->paths);
    }

    public function testTheSubmodulesFileIsProtectedWithoutBeingListed(): void
    {
        $head = $this->givenABranchCommitting('.gitmodules', '[submodule "lib"]');

        self::assertSame(['.gitmodules'], $this->whenTheHeadIsListed($head)->paths);
    }

    public function testASubmoduleEntryIsProtectedWithoutBeingListed(): void
    {
        $this->repository->checkoutNewBranch('feat/side');
        $head = $this->repository->commitSubmodule('lib', $this->base);

        self::assertSame(['lib'], $this->whenTheHeadIsListed($head)->paths);
    }

    public function testARenameOutOfAProtectedFolderListsTheOldPath(): void
    {
        $this->repository->checkoutNewBranch('feat/side');
        rename($this->repository->path.'/config/gates/rule.php', $this->repository->path.'/src/rule.php');
        $head = $this->repository->commitFile('src/rule.php', 'one');

        self::assertSame(['config/gates/rule.php'], $this->whenTheHeadIsListed($head)->paths);
    }

    public function testAHeadWithoutACommonAncestorListsEveryProtectedFileItHolds(): void
    {
        $this->repository->checkoutOrphan('feat/side');
        $this->repository->commitFile('src/a.php', 'one');
        $head = $this->repository->commitFile('bin/check', 'one');

        self::assertSame(['bin/check'], $this->whenTheHeadIsListed($head)->paths);
    }

    public function testAMissingObjectCountsAsProtected(): void
    {
        $head = $this->givenABranchCommitting('src/a.php', 'two');
        $this->repository->checkout('main');
        $this->repository->removeObject($this->repository->objectAt($head.'^{tree}'));

        $changes = $this->listed($this->repository->path.'/.git', $head);

        self::assertTrue($changes->touchesProtectedPaths());
        self::assertStringContainsString('git diff-tree failed', (string) $changes->unreadable);
    }

    public function testAShallowHistoryCountsAsProtected(): void
    {
        $this->givenABranchCommitting('bin/check', 'two');
        $head = $this->repository->removeFile('bin/check');
        $this->repository->cutHistoryBelow($head);

        $changes = $this->whenTheHeadIsListed($head);

        self::assertTrue($changes->touchesProtectedPaths());
        self::assertSame('the change could not be read: the repository\'s history is shallow, so commits are missing', $changes->unreadable);
    }

    public function testAnAnnotatedTagCountsAsProtected(): void
    {
        $this->givenABranchCommitting('src/a.php', 'two');
        $tag = $this->repository->annotatedTag('v1', 'HEAD');

        $changes = $this->whenTheHeadIsListed($tag);

        self::assertTrue($changes->touchesProtectedPaths());
        self::assertSame(sprintf('the change could not be read: %s is not a commit of the repository', $tag), $changes->unreadable);
    }

    public function testACommitTheRepositoryDoesNotHoldCountsAsProtected(): void
    {
        $changes = $this->whenTheHeadIsListed(str_repeat('a', 40));

        self::assertTrue($changes->touchesProtectedPaths());
    }

    public function testARepositoryWhoseHeadNamesNoBranchCountsAsProtected(): void
    {
        $head = $this->givenABranchCommitting('src/a.php', 'two');
        $this->repository->checkoutDetached('main');

        $changes = $this->listed($this->repository->path.'/.git', $head);

        self::assertSame('the change could not be read: the repository\'s HEAD names no branch, so main is unknown', $changes->unreadable);
    }

    public function testAMissingRepositoryCountsAsProtected(): void
    {
        $changes = $this->listed($this->repository->path.'/missing.git', $this->base);

        self::assertTrue($changes->touchesProtectedPaths());
    }

    public function testAHeadThatIsNoObjectNameIsRefused(): void
    {
        $this->expectException(CommitNameRefused::class);
        $this->expectExceptionMessageIsOrContains('"main" is not a full commit name');

        $this->whenTheHeadIsListed('main');
    }

    public function testASha256RepositoryIsRead(): void
    {
        $repository = new TemporaryRepository('sha256');

        try {
            $this->givenMainWithTheProtectedFiles($repository);
            $repository->checkoutNewBranch('feat/side');
            $head = $repository->commitFile('bin/check', 'two');
            $repository->checkout('main');

            self::assertSame(['bin/check'], $this->listed($repository->path.'/.git', $head)->paths);
        } finally {
            $repository->remove();
        }
    }

    private function givenMainWithTheProtectedFiles(TemporaryRepository $repository): void
    {
        $repository->commitFile('bin/check', 'one');
        $repository->commitFile('src/a.php', 'one');
        $this->base = $repository->commitFile('config/gates/rule.php', 'one');
    }

    private function givenABranchCommitting(string $path, string $content, string $branch = 'feat/side'): string
    {
        $this->repository->checkoutNewBranch($branch);

        return $this->repository->commitFile($path, $content);
    }

    private function whenTheHeadIsListed(string $head): ProtectedChanges
    {
        $this->repository->checkout('main');

        return $this->listed($this->repository->path.'/.git', $head);
    }

    private function listed(string $repository, string $head): ProtectedChanges
    {
        return new ListProtectedChanges(new ChangedPaths(new HardenedGit()))->of($repository, $head, new ProtectedPaths(['bin/check', 'config/gates/', '.github']));
    }

    /**
     * @param callable(): ProtectedChanges $check
     */
    private function whileMainAdvancesTo(string $commit, string $comparedPair, callable $check): ProtectedChanges
    {
        // A git on PATH that moves main before it runs the diff-tree of the pair, as a mirror update beside the check would.
        $path = (string) getenv('PATH');
        $shim = sys_get_temp_dir().'/intakt-git-shim-'.bin2hex(random_bytes(6));
        mkdir($shim);
        file_put_contents($shim.'/git', implode("\n", [
            '#!/bin/sh',
            'PATH='.escapeshellarg($path),
            'export PATH',
            'case " $* " in *" diff-tree "*" '.$comparedPair.' ") git --git-dir='.escapeshellarg($this->repository->path.'/.git').' update-ref refs/heads/main '.$commit.';; esac',
            'exec git "$@"',
            '',
        ]));
        chmod($shim.'/git', 0o755);
        putenv('PATH='.$shim.':'.$path);

        try {
            return $check();
        } finally {
            putenv('PATH='.$path);
            unlink($shim.'/git');
            rmdir($shim);
        }
    }
}
