<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge\Infrastructure;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Plugin\Forge\Contract\BranchChange;
use Intakt\Plugin\Forge\Contract\BranchChangeState;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\ForgeBranch;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalForgeBranches;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversClass(LocalForgeBranches::class)]
#[UsesClass(BranchChange::class)]
#[UsesClass(BranchChangeState::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(ForgeBranch::class)]
#[UsesClass(ForgeReadFailed::class)]
#[UsesClass(ForgeWriteFailed::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(LocalRepository::class)]
final class LocalForgeBranchesTest extends TestCase
{
    // Stands for Intakt's identity, because TemporaryRepository commits with this email.
    private const string INTAKT = 'test@example.invalid';

    private TemporaryRepository $repository;

    public function testItListsEachBranchWithItsHead(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $head = $this->repository->commitFile('src/New.php', "<?php\n");

        $branch = $this->branch('intakt/feature');

        self::assertSame($head, $branch->head->hash);
        self::assertTrue($branch->isDeletable());
    }

    public function testItListsTheBaseBranchAsTheDefaultAndNeverAsDeletable(): void
    {
        $main = $this->branch('main');

        self::assertTrue($main->isDefault);
        self::assertFalse($main->isDeletable());
    }

    public function testItListsAReleaseBranchAsSuchAndNeverAsDeletable(): void
    {
        $this->repository->checkoutNewBranch('release/1.0');

        $release = $this->branch('release/1.0');

        self::assertTrue($release->isRelease());
        self::assertFalse($release->isDefault);
        self::assertFalse($release->isDeletable());
    }

    public function testItListsNoBranchAsProtectedBecauseALocalRepositoryProtectsNone(): void
    {
        self::assertFalse($this->branch('main')->isProtected);
    }

    public function testItListsASymbolicBranchAsSymbolicAndNeverAsDeletable(): void
    {
        $this->repository->aliasBranch('intakt/main', 'main');

        $alias = $this->branch('intakt/main');

        self::assertTrue($alias->isSymbolic);
        self::assertFalse($alias->isDeletable());
    }

    public function testDeletingAnIntaktAliasOfMainLeavesMainInPlace(): void
    {
        $this->repository->aliasBranch('intakt/main', 'main');
        $main = $this->repository->objectAt('main');
        $alias = $this->branch('intakt/main');

        try {
            $this->branches()->delete(new ForgeBranch($alias->name, $alias->head, false, false, false));
            self::fail('The alias was deleted.');
        } catch (ForgeWriteFailed) {
            self::assertSame($main, $this->repository->objectAt('refs/heads/main'));
        }
    }

    public function testItRefusesToDeleteABranchThatBecameSymbolicAfterTheListing(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $this->repository->checkout('main');
        $listed = $this->branch('intakt/feature');
        $this->repository->deleteBranch('intakt/feature');
        $this->repository->aliasBranch('intakt/feature', 'main');
        $main = $this->repository->objectAt('refs/heads/main');

        try {
            $this->branches()->delete($listed);
            self::fail('The branch that became symbolic was deleted.');
        } catch (ForgeWriteFailed) {
            self::assertSame($main, $this->repository->objectAt('refs/heads/main'));
        }
    }

    public function testACheckoutDuringTheDeleteIsHeldOffSoHeadNeverNamesADeletedBranch(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $this->repository->checkout('main');
        $main = $this->repository->objectAt('HEAD');
        $listed = $this->branch('intakt/feature');

        $this->withGitThatRunsAfterTheWorktreeListing(['switch', '--quiet', 'intakt/feature'], function () use ($listed): void {
            $this->branches()->delete($listed);
        });

        self::assertSame($main, $this->repository->objectAt('HEAD'));
        self::assertNotContains('intakt/feature', $this->names());
    }

    public function testABranchAWorktreeChecksOutDuringTheDeleteIsPutBackAtItsHead(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $head = $this->repository->commitFile('src/New.php', "<?php\n");
        $this->repository->checkout('main');
        $listed = $this->branch('intakt/feature');

        try {
            $this->withGitThatRunsAfterTheWorktreeListing(['worktree', 'add', '--quiet', $this->worktree(), 'intakt/feature'], function () use ($listed): void {
                $this->branches()->delete($listed);
            });
            self::fail('The delete did not report the checkout.');
        } catch (ForgeWriteFailed) {
            self::assertSame($head, $this->repository->objectAt('refs/heads/intakt/feature'));
        }
    }

    public function testItRefusesToDeleteTheBranchTheHeadOfABareRepositoryNames(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $this->repository->checkout('main');
        $bare = $this->worktree();
        new Process(['git', 'clone', '--quiet', '--bare', $this->repository->path, $bare])->mustRun();
        new Process(['git', 'symbolic-ref', 'HEAD', 'refs/heads/intakt/feature'], $bare)->mustRun();
        $branches = new LocalForgeBranches(new LocalRepository($bare, 'main'));
        $listed = array_find($branches->branches(), static fn (ForgeBranch $branch): bool => 'intakt/feature' === $branch->name);
        self::assertInstanceOf(ForgeBranch::class, $listed);

        try {
            $branches->delete($listed);
            self::fail('The branch HEAD names was deleted.');
        } catch (ForgeWriteFailed) {
            self::assertTrue(0 === new Process(['git', 'show-ref', '--verify', '--quiet', 'refs/heads/intakt/feature'], $bare)->run());
        }
    }

    public function testItRefusesToDeleteTheBaseBranchEvenWhenTheCallerSaysItIsNotTheDefault(): void
    {
        $this->repository->checkoutNewBranch('other');
        $main = $this->branch('main');

        $this->expectException(ForgeWriteFailed::class);

        $this->branches()->delete(new ForgeBranch('main', $main->head, false, false, false));
    }

    public function testItDoesNotDeleteABranchCheckedOutAfterTheListing(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $head = $this->repository->commitFile('src/New.php', "<?php\n");
        $this->repository->checkout('main');
        $listed = $this->branch('intakt/feature');
        $this->repository->checkout('intakt/feature');

        try {
            $this->branches()->delete($listed);
            self::fail('The checked-out branch was deleted.');
        } catch (ForgeWriteFailed) {
            self::assertSame($head, $this->repository->objectAt('refs/heads/intakt/feature'));
        }
    }

    public function testItDoesNotDeleteABranchCheckedOutInAnotherWorktree(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $this->repository->checkout('main');
        $listed = $this->branch('intakt/feature');
        $this->repository->addWorktree($this->worktree(), 'intakt/feature');

        $this->expectException(ForgeWriteFailed::class);

        $this->branches()->delete($listed);
    }

    public function testItDoesNotDeleteABranchWhoseHeadMovedAfterTheListing(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $listed = $this->branch('intakt/feature');
        $moved = $this->repository->commitFile('src/New.php', "<?php\n");
        $this->repository->checkout('main');

        try {
            $this->branches()->delete($listed);
            self::fail('The moved branch was deleted.');
        } catch (ForgeWriteFailed) {
            self::assertSame($moved, $this->repository->objectAt('refs/heads/intakt/feature'));
        }
    }

    public function testItDeletesABranchAtItsExpectedHead(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $this->repository->commitFile('src/New.php', "<?php\n");
        $this->repository->checkout('main');

        $this->branches()->delete($this->branch('intakt/feature'));

        self::assertNotContains('intakt/feature', $this->names());
    }

    public function testABranchWithCommitsOfItsOwnHasAnOpenChangeWithoutLabels(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $this->repository->commitFile('src/New.php', "<?php\n");

        $change = $this->branches()->changeOf($this->branch('intakt/feature'));

        self::assertSame(BranchChangeState::Open, $change?->state);
        self::assertSame([], $change->labels);
        self::assertFalse($change->hasLabel('keep'));
    }

    public function testABranchWhoseHeadIsInTheBaseBranchHasAMergedChange(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $this->repository->commitFile('src/New.php', "<?php\n");
        $this->repository->checkout('main');
        $this->repository->merge('intakt/feature');

        self::assertSame(BranchChangeState::Merged, $this->branches()->changeOf($this->branch('intakt/feature'))?->state);
    }

    public function testTheBaseBranchHasNoChange(): void
    {
        self::assertNull($this->branches()->changeOf($this->branch('main')));
    }

    public function testAChangeThatCannotBeReadThrowsInsteadOfCountingAsNoChange(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $listed = $this->branch('intakt/feature');
        $this->repository->remove();

        $this->expectException(ForgeReadFailed::class);

        $this->branches()->changeOf($listed);
    }

    public function testATagNamedLikeAMissingBaseBranchDoesNotStandInForIt(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $this->repository->tag('refs/heads/missing');
        $listed = $this->branch('intakt/feature');

        $this->expectException(ForgeReadFailed::class);

        new LocalForgeBranches(new LocalRepository($this->repository->path, 'missing'))->changeOf($listed);
    }

    public function testAShallowHistoryThrowsInsteadOfCountingAsNoCommitByAPerson(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $this->repository->commitFileAs('person@example.invalid', 'OTHER.md', "other\n");
        $tip = $this->repository->commitFile('src/New.php', "<?php\n");
        $listed = $this->branch('intakt/feature');
        $this->repository->cutHistoryBelow($tip);

        $this->expectException(ForgeReadFailed::class);

        $this->branches()->hasCommitByAPerson($listed, self::INTAKT);
    }

    public function testAShallowHistoryThrowsInsteadOfReadingAChangeAsOpen(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $tip = $this->repository->commitFile('src/New.php', "<?php\n");
        $listed = $this->branch('intakt/feature');
        $this->repository->cutHistoryBelow($tip);

        $this->expectException(ForgeReadFailed::class);

        $this->branches()->changeOf($listed);
    }

    public function testAListingWithADanglingSymbolicBranchThrowsInsteadOfLeavingItOut(): void
    {
        $this->repository->aliasBranch('intakt/dangling', 'missing');

        $this->expectException(ForgeReadFailed::class);

        $this->branches()->branches();
    }

    public function testAListingWithABrokenBranchThrowsInsteadOfLeavingItOut(): void
    {
        file_put_contents($this->repository->path.'/.git/refs/heads/intakt-broken', "not a hash\n");

        $this->expectException(ForgeReadFailed::class);

        $this->branches()->branches();
    }

    public function testAListingIgnoresALockGitHoldsOnABranch(): void
    {
        file_put_contents($this->repository->path.'/.git/refs/heads/main.lock', '');

        self::assertSame(['main'], $this->names());
    }

    public function testABranchWithOnlyIntaktsCommitsHasNoCommitByAPerson(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $this->repository->commitFile('src/New.php', "<?php\n");

        self::assertFalse($this->branches()->hasCommitByAPerson($this->branch('intakt/feature'), self::INTAKT));
    }

    public function testABranchWithAPersonsCommitHasACommitByAPerson(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $this->repository->commitFile('src/New.php', "<?php\n");
        $this->repository->commitFileAs('person@example.invalid', 'src/Other.php', "<?php\n");

        self::assertTrue($this->branches()->hasCommitByAPerson($this->branch('intakt/feature'), self::INTAKT));
    }

    public function testAPersonsCommitOnTheBaseBranchDoesNotClaimABranchCutFromIt(): void
    {
        $this->repository->commitFileAs('person@example.invalid', 'OTHER.md', "other\n");
        $this->repository->checkoutNewBranch('intakt/feature');
        $this->repository->commitFile('src/New.php', "<?php\n");

        self::assertFalse($this->branches()->hasCommitByAPerson($this->branch('intakt/feature'), self::INTAKT));
    }

    public function testIntaktsIdentityIsMatchedWhateverTheLetterCase(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $this->repository->commitFile('src/New.php', "<?php\n");

        self::assertFalse($this->branches()->hasCommitByAPerson($this->branch('intakt/feature'), 'Test@Example.Invalid'));
    }

    public function testACommitWithoutAnAuthorEmailThrowsInsteadOfCountingAsIntakts(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $this->repository->commitFileAs('', 'NEW.md', "new\n");

        $this->expectException(ForgeReadFailed::class);

        $this->branches()->hasCommitByAPerson($this->branch('intakt/feature'), self::INTAKT);
    }

    public function testCommitsThatCannotBeReadThrowInsteadOfCountingAsNoCommitByAPerson(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $listed = $this->branch('intakt/feature');
        $this->repository->remove();

        $this->expectException(ForgeReadFailed::class);

        $this->branches()->hasCommitByAPerson($listed, self::INTAKT);
    }

    public function testAnEmptyIntaktIdentityIsRefusedBecauseEveryCommitWouldCountAsAPersons(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');

        $this->expectException(ForgeReadFailed::class);

        $this->branches()->hasCommitByAPerson($this->branch('intakt/feature'), ' ');
    }

    public function testAListingThatCannotBeReadThrows(): void
    {
        $this->repository->remove();

        $this->expectException(ForgeReadFailed::class);

        $this->branches()->branches();
    }

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('README.md', "base\n");
    }

    protected function tearDown(): void
    {
        $this->repository->remove();
        new Filesystem()->remove($this->worktree());
    }

    private function branches(): LocalForgeBranches
    {
        return new LocalForgeBranches(new LocalRepository($this->repository->path, 'main'));
    }

    private function branch(string $name): ForgeBranch
    {
        foreach ($this->branches()->branches() as $branch) {
            if ($name === $branch->name) {
                return $branch;
            }
        }

        self::fail(sprintf('The branch "%s" is not listed.', $name));
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        return array_map(static fn (ForgeBranch $branch): string => $branch->name, $this->branches()->branches());
    }

    private function worktree(): string
    {
        return $this->repository->path.'-worktree';
    }

    /**
     * @param list<string>     $injected
     * @param callable(): void $action
     */
    // A git first on the path that runs another git command right after each worktree listing, so the test forces the interleaving a concurrent checkout would need: after the checked-out check, before the delete.
    private function withGitThatRunsAfterTheWorktreeListing(array $injected, callable $action): void
    {
        $real = escapeshellarg(trim(new Process(['sh', '-c', 'command -v git'])->mustRun()->getOutput()));
        $bin = $this->repository->path.'-bin';
        new Filesystem()->dumpFile($bin.'/git', sprintf(
            "#!/bin/sh\n%s \"\$@\"\nstatus=\$?\ncase \" \$* \" in\n  *' worktree list '*) env -i PATH=\"\$PATH\" %s -C %s %s >/dev/null 2>&1 ;;\nesac\nexit \$status\n",
            $real,
            $real,
            escapeshellarg($this->repository->path),
            implode(' ', array_map(escapeshellarg(...), $injected)),
        ));
        chmod($bin.'/git', 0o755);
        $path = (string) getenv('PATH');
        $this->usePath($bin.':'.$path);

        try {
            $action();
        } finally {
            $this->usePath($path);
            new Filesystem()->remove($bin);
        }
    }

    private function usePath(string $path): void
    {
        putenv('PATH='.$path);
        $_SERVER['PATH'] = $path;
        $_ENV['PATH'] = $path;
    }
}
