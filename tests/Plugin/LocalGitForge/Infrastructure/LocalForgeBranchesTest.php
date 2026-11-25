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
use Intakt\Plugin\LocalGitForge\Infrastructure\RebasesAndBisects;
use Intakt\Plugin\LocalGitForge\Infrastructure\WorktreeGitDirectories;
use Intakt\Plugin\LocalGitForge\Infrastructure\WorktreeLocks;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
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
#[UsesClass(RebasesAndBisects::class)]
#[UsesClass(WorktreeGitDirectories::class)]
#[UsesClass(WorktreeLocks::class)]
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

    public function testABranchIsPutBackWhenTheCheckAfterTheDeleteCannotBeRead(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $head = $this->repository->commitFile('src/New.php', "<?php\n");
        $this->repository->checkout('main');
        $listed = $this->branch('intakt/feature');

        try {
            $this->withGit($this->aWorktreeAddAfterTheFirstListingAndNoSecondListing(), function () use ($listed): void {
                $this->branches()->delete($listed);
            });
            self::fail('The delete did not report the check it could not read.');
        } catch (ForgeWriteFailed $refused) {
            self::assertStringContainsString('put back at its head', $refused->getMessage());
            self::assertSame($head, $this->repository->objectAt('refs/heads/intakt/feature'));
            self::assertSame($head, $this->headOf($this->worktree()));
        }
    }

    public function testADeleteThatCannotPutTheBranchBackSaysTheBranchIsDeleted(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $head = $this->repository->commitFile('src/New.php', "<?php\n");
        $this->repository->checkout('main');
        $listed = $this->branch('intakt/feature');
        $noPutBack = "  *' update-ref --no-deref '*) echo 'put-back refused' >&2; exit 128 ;;\n";

        try {
            $this->withGit($noPutBack.$this->aWorktreeAddAfterTheFirstListingAndNoSecondListing(), function () use ($listed): void {
                $this->branches()->delete($listed);
            });
            self::fail('The delete did not report the branch it could not put back.');
        } catch (ForgeWriteFailed $lost) {
            self::assertStringContainsString(sprintf('The branch "intakt/feature" is deleted at %s and could not be put back', $head), $lost->getMessage());
            self::assertStringNotContainsString('was not deleted', $lost->getMessage());
            self::assertFalse($this->exists($this->repository->path, 'intakt/feature'));
        }
    }

    public function testABranchMadeAgainBeforeThePutBackIsReportedAsDeletedAndExistingAgainAtItsNewCommit(): void
    {
        $main = $this->repository->objectAt('HEAD');
        $this->repository->checkoutNewBranch('intakt/feature');
        $head = $this->repository->commitFile('src/New.php', "<?php\n");
        $this->repository->checkout('main');
        $listed = $this->branch('intakt/feature');
        $git = $this->realGit();
        $madeAgain = sprintf("  *' update-ref --no-deref '*) %s; exec %s \"\$@\" ;;\n", $this->inTheRepository(['update-ref', 'refs/heads/intakt/feature', $main]), $git);

        try {
            $this->withGit($madeAgain.$this->aWorktreeAddAfterTheFirstListingAndNoSecondListing(), function () use ($listed): void {
                $this->branches()->delete($listed);
            });
            self::fail('The delete did not report the branch made again.');
        } catch (ForgeWriteFailed $lost) {
            self::assertStringContainsString(sprintf('The branch "intakt/feature" was deleted at %s and exists again at %s', $head, $main), $lost->getMessage());
            self::assertSame($main, $this->repository->objectAt('refs/heads/intakt/feature'));
        }
    }

    public function testABranchWhoseStateCannotBeReadAfterAFailedPutBackIsReportedAsDeletedWithAnUnreadableState(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $head = $this->repository->commitFile('src/New.php', "<?php\n");
        $this->repository->checkout('main');
        $listed = $this->branch('intakt/feature');
        $noPutBackNorRead = "  *' update-ref --no-deref '*) echo 'put-back refused' >&2; exit 128 ;;\n  *' show-ref '*) echo 'read refused' >&2; exit 128 ;;\n";

        try {
            $this->withGit($noPutBackNorRead.$this->aWorktreeAddAfterTheFirstListingAndNoSecondListing(), function () use ($listed): void {
                $this->branches()->delete($listed);
            });
            self::fail('The delete did not report the branch it could not put back.');
        } catch (ForgeWriteFailed $lost) {
            self::assertStringContainsString(sprintf('The branch "intakt/feature" was deleted at %s and its current state could not be read', $head), $lost->getMessage());
            self::assertStringNotContainsString('was not deleted', $lost->getMessage());
        }
    }

    public function testABranchMadeAgainAsASymbolicRefToABlobIsNotReportedAsExistingAgainAtACommit(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $head = $this->repository->commitFile('src/New.php', "<?php\n");
        $this->repository->checkout('main');
        new Process(['git', 'update-ref', 'refs/tags/blob', $this->repository->objectAt('HEAD:README.md')], $this->repository->path)->mustRun();
        $listed = $this->branch('intakt/feature');
        $madeAgainAtABlob = sprintf("  *' update-ref --no-deref '*) %s; exit 128 ;;\n", $this->inTheRepository(['symbolic-ref', 'refs/heads/intakt/feature', 'refs/tags/blob']));

        try {
            $this->withGit($madeAgainAtABlob.$this->aWorktreeAddAfterTheFirstListingAndNoSecondListing(), function () use ($listed): void {
                $this->branches()->delete($listed);
            });
            self::fail('The delete did not report the branch it could not put back.');
        } catch (ForgeWriteFailed $lost) {
            self::assertStringContainsString(sprintf('The branch "intakt/feature" was deleted at %s and its current state could not be read', $head), $lost->getMessage());
        }
    }

    public function testItRefusesToDeleteWhileAWorktreeIsStillBeingAdded(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $this->repository->checkout('main');
        $listed = $this->branch('intakt/feature');
        $this->addDetachedWorktreeLockedBecause('initializing');

        try {
            $this->branches()->delete($listed);
            self::fail('The branch was deleted while a worktree was being added.');
        } catch (ForgeWriteFailed) {
            self::assertTrue($this->exists($this->repository->path, 'intakt/feature'));
        }
    }

    public function testItDeletesABranchWhileAWorktreeIsLockedForAnotherReason(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $this->repository->checkout('main');
        $listed = $this->branch('intakt/feature');
        $this->addDetachedWorktreeLockedBecause('on a removable disk');

        $this->branches()->delete($listed);

        self::assertFalse($this->exists($this->repository->path, 'intakt/feature'));
    }

    public function testItRefusesToDeleteTheBranchABareRepositorysHeadNamesWhenOpenedThroughALinkedWorktree(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $this->repository->checkout('main');
        $bare = $this->worktree();
        new Process(['git', 'clone', '--quiet', '--bare', $this->repository->path, $bare])->mustRun();
        new Process(['git', 'symbolic-ref', 'HEAD', 'refs/heads/intakt/feature'], $bare)->mustRun();
        new Process(['git', 'worktree', 'add', '--quiet', $this->linked(), 'main'], $bare)->mustRun();
        $branches = new LocalForgeBranches(new LocalRepository($this->linked(), 'main'));
        $listed = array_find($branches->branches(), static fn (ForgeBranch $branch): bool => 'intakt/feature' === $branch->name);
        self::assertInstanceOf(ForgeBranch::class, $listed);

        try {
            $branches->delete($listed);
            self::fail('The branch the bare repository\'s HEAD names was deleted.');
        } catch (ForgeWriteFailed) {
            self::assertTrue($this->exists($bare, 'intakt/feature'));
        }
    }

    public function testACheckoutHeldOffDuringTheDeleteLeavesItsWorkingTreeUntouched(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $this->repository->commitFile('src/New.php', "<?php\n");
        $this->repository->checkout('main');
        $listed = $this->branch('intakt/feature');

        $this->withGitThatRunsAfterTheWorktreeListing(['switch', '--quiet', 'intakt/feature'], function () use ($listed): void {
            $this->branches()->delete($listed);
        });

        self::assertFileDoesNotExist($this->repository->path.'/src/New.php');
        self::assertSame('', new Process(['git', 'status', '--porcelain'], $this->repository->path)->mustRun()->getOutput());
    }

    public function testItRefusesToDeleteWhileAnotherGitCommandHoldsHeadAndLeavesNoLockOfItsOwn(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $this->repository->checkout('main');
        $listed = $this->branch('intakt/feature');
        new Filesystem()->touch($this->repository->path.'/.git/HEAD.lock');

        try {
            $this->branches()->delete($listed);
            self::fail('The delete ignored a HEAD.lock.');
        } catch (ForgeWriteFailed) {
            self::assertTrue($this->exists($this->repository->path, 'intakt/feature'));
            self::assertFileDoesNotExist($this->repository->path.'/.git/index.lock');
        }
    }

    public function testItRefusesToDeleteWhileAnotherGitCommandHoldsTheIndex(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $this->repository->checkout('main');
        $listed = $this->branch('intakt/feature');
        new Filesystem()->touch($this->repository->path.'/.git/index.lock');

        try {
            $this->branches()->delete($listed);
            self::fail('The delete ignored an index.lock.');
        } catch (ForgeWriteFailed) {
            self::assertTrue($this->exists($this->repository->path, 'intakt/feature'));
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

    public function testItDoesNotDeleteABranchBeingRebasedInTheRepository(): void
    {
        $listed = $this->aBranchThatConflictsWithMain('intakt/feature');
        $this->repository->checkout('intakt/feature');
        $this->rebaseOntoMainStopsIn($this->repository->path);

        try {
            $this->branches()->delete($listed);
            self::fail('The branch being rebased was deleted.');
        } catch (ForgeWriteFailed) {
            self::assertSame($listed->head->hash, $this->repository->objectAt('refs/heads/intakt/feature'));
        }
    }

    public function testItDoesNotDeleteABranchBeingRebasedInAnotherWorktree(): void
    {
        $listed = $this->aBranchThatConflictsWithMain('intakt/feature');
        $this->repository->addWorktree($this->worktree(), 'intakt/feature');
        $this->rebaseOntoMainStopsIn($this->worktree());

        try {
            $this->branches()->delete($listed);
            self::fail('The branch being rebased in another worktree was deleted.');
        } catch (ForgeWriteFailed) {
            self::assertSame($listed->head->hash, $this->repository->objectAt('refs/heads/intakt/feature'));
        }
    }

    #[TestWith(['apply', 'rebase-apply', null])]
    #[TestWith(['apply', 'rebase-apply', ''])]
    #[TestWith(['merge', 'rebase-merge', ''])]
    #[TestWith(['merge', 'rebase-merge', "invalid branch\n"])]
    #[TestWith(['apply', 'rebase-apply', "invalid branch\n"])]
    #[TestWith(['merge', 'rebase-merge', "intakt/feature\n"])]
    #[TestWith(['merge', 'rebase-merge', "refs/heads/intakt/feature..old\n"])]
    #[TestWith(['apply', 'rebase-apply', "refs/heads/intakt/feature.lock\n"])]
    #[TestWith(['merge', 'rebase-merge', "refs/heads/intakt/feature@{1}\n"])]
    public function testItDoesNotDeleteABranchWhoseRebaseStateNamesNoBranch(string $backend, string $state, ?string $headName): void
    {
        $listed = $this->aBranchThatConflictsWithMain('intakt/feature');
        $this->repository->checkout('intakt/feature');
        $this->rebaseOntoMainStopsIn($this->repository->path, $backend);
        $file = $this->repository->path.'/.git/'.$state.'/head-name';
        null === $headName ? new Filesystem()->remove($file) : new Filesystem()->dumpFile($file, $headName);

        try {
            $this->branches()->delete($listed);
            self::fail('The branch being rebased was deleted.');
        } catch (ForgeWriteFailed $refused) {
            self::assertInstanceOf(ForgeReadFailed::class, $refused->getPrevious());
            self::assertSame($listed->head->hash, $this->repository->objectAt('refs/heads/intakt/feature'));
        }
    }

    public function testItDoesNotDeleteABranchWhileAnApplyStateCannotTellARebaseFromAnAm(): void
    {
        $listed = $this->aBranchThatConflictsWithMain('intakt/feature');
        $this->repository->checkout('intakt/feature');
        $this->rebaseOntoMainStopsIn($this->repository->path, 'apply');
        new Filesystem()->remove([$this->repository->path.'/.git/rebase-apply/rebasing', $this->repository->path.'/.git/rebase-apply/head-name']);

        $this->expectException(ForgeWriteFailed::class);

        $this->branches()->delete($listed);
    }

    public function testItDeletesABranchWhileAnAmIsUnderWayOnAnotherBranch(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $this->repository->checkout('main');
        $listed = $this->branch('intakt/feature');
        $this->aBranchThatConflictsWithMain('intakt/other');
        $patch = new Process(['git', 'format-patch', '-1', '--stdout', 'intakt/other'], $this->repository->path)->mustRun()->getOutput();
        $am = new Process(['git', '-c', 'user.name=Test', '-c', 'user.email='.self::INTAKT, 'am'], $this->repository->path, input: $patch);
        self::assertNotSame(0, $am->run(), 'The am did not stop on its conflict.');

        $this->branches()->delete($listed);

        self::assertNotContains('intakt/feature', $this->names());
    }

    public function testItDoesNotDeleteABranchABisectStartedOn(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $this->repository->commitFile('src/One.php', "<?php\n");
        $head = $this->repository->commitFile('src/Two.php', "<?php\n");
        $listed = $this->branch('intakt/feature');
        new Process(['git', 'bisect', 'start', 'intakt/feature', 'main'], $this->repository->path)->mustRun();

        try {
            $this->branches()->delete($listed);
            self::fail('The branch a bisect started on was deleted.');
        } catch (ForgeWriteFailed) {
            self::assertSame($head, $this->repository->objectAt('refs/heads/intakt/feature'));
        }
    }

    public function testItDeletesABranchWhileAnotherBranchIsBeingRebased(): void
    {
        $this->repository->checkoutNewBranch('intakt/feature');
        $this->repository->checkout('main');
        $listed = $this->branch('intakt/feature');
        $this->aBranchThatConflictsWithMain('intakt/other');
        $this->repository->checkout('intakt/other');
        $this->rebaseOntoMainStopsIn($this->repository->path);

        $this->branches()->delete($listed);

        self::assertNotContains('intakt/feature', $this->names());
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
        new Filesystem()->remove([$this->worktree(), $this->linked()]);
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
     * @param list<string>     $injected
     * @param callable(): void $action
     */
    // A git first on the path that runs another git command right after each worktree listing, so the test forces the interleaving a concurrent checkout would need: after the checked-out check, before the delete.
    private function withGitThatRunsAfterTheWorktreeListing(array $injected, callable $action): void
    {
        $this->withGit(sprintf(
            "  *' worktree list '*) %s \"\$@\"; status=\$?; %s; exit \$status ;;\n",
            $this->realGit(),
            $this->inTheRepository($injected),
        ), $action);
    }

    // The first worktree listing is followed by a worktree add of the branch and every later one fails, so the test forces the order where the delete has happened and the check after it cannot answer.
    private function aWorktreeAddAfterTheFirstListingAndNoSecondListing(): string
    {
        $mark = escapeshellarg($this->bin().'/listed');

        return sprintf(
            "  *' worktree list '*) test ! -e %s || exit 128; %s \"\$@\"; status=\$?; %s; touch %s; exit \$status ;;\n",
            $mark,
            $this->realGit(),
            $this->inTheRepository(['worktree', 'add', '--quiet', $this->worktree(), 'intakt/feature']),
            $mark,
        );
    }

    // The branch and main both change README.md, so a rebase of the branch onto main stops on the conflict.
    private function aBranchThatConflictsWithMain(string $name): ForgeBranch
    {
        $this->repository->checkoutNewBranch($name);
        $this->repository->commitFile('README.md', $name."\n");
        $this->repository->checkout('main');
        $this->repository->commitFile('README.md', "main\n");

        return $this->branch($name);
    }

    private function rebaseOntoMainStopsIn(string $worktree, string $backend = 'merge'): void
    {
        $rebase = new Process(['git', '-c', 'user.name=Test', '-c', 'user.email='.self::INTAKT, 'rebase', '--'.$backend, 'main'], $worktree);

        self::assertNotSame(0, $rebase->run(), 'The rebase did not stop on its conflict.');
    }

    private function realGit(): string
    {
        return escapeshellarg(trim(new Process(['sh', '-c', 'command -v git'])->mustRun()->getOutput()));
    }

    /**
     * @param list<string> $command
     */
    private function inTheRepository(array $command): string
    {
        return sprintf('env -i PATH="$PATH" %s -C %s %s >/dev/null 2>&1', $this->realGit(), escapeshellarg($this->repository->path), implode(' ', array_map(escapeshellarg(...), $command)));
    }

    /**
     * @param callable(): void $action
     */
    // A git first on the path, because only a command that runs inside the delete's own git calls can force the order a concurrent git command would need.
    private function withGit(string $cases, callable $action): void
    {
        $bin = $this->bin();
        new Filesystem()->dumpFile($bin.'/git', sprintf("#!/bin/sh\ncase \" \$* \" in\n%s  *) exec %s \"\$@\" ;;\nesac\n", $cases, $this->realGit()));
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

    private function linked(): string
    {
        return $this->repository->path.'-linked';
    }

    private function bin(): string
    {
        return $this->repository->path.'-bin';
    }

    private function exists(string $repository, string $branch): bool
    {
        return 0 === new Process(['git', 'show-ref', '--verify', '--quiet', 'refs/heads/'.$branch], $repository)->run();
    }

    private function headOf(string $worktree): string
    {
        return trim(new Process(['git', 'rev-parse', '--verify', 'HEAD'], $worktree)->mustRun()->getOutput());
    }

    // git worktree add locks a worktree with the reason "initializing" until its HEAD is written, so the lock file stands for an add that is under way.
    private function addDetachedWorktreeLockedBecause(string $reason): void
    {
        new Process(['git', 'worktree', 'add', '--quiet', '--detach', $this->worktree()], $this->repository->path)->mustRun();
        new Filesystem()->dumpFile(sprintf('%s/.git/worktrees/%s/locked', $this->repository->path, basename($this->worktree())), $reason."\n");
    }
}
