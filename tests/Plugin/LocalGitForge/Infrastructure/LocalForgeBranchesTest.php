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

        $this->expectException(ForgeWriteFailed::class);

        $this->branches()->delete($listed);
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
}
