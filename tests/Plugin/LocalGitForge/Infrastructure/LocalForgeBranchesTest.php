<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge\Infrastructure;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\GitCommand;
use Intakt\Core\Git\Contract\GitEnvironment;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\ForgeBranch;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalForgeBranches;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(LocalForgeBranches::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(GitCommand::class)]
#[UsesClass(GitEnvironment::class)]
#[UsesClass(ForgeBranch::class)]
#[UsesClass(CommitId::class)]
final class LocalForgeBranchesTest extends TestCase
{
    private TemporaryRepository $repository;
    private string $main;
    private string $head;

    public function testTheBranchesAreListedWithTheCheckedOutOneAsTheDefaultAndNoneProtected(): void
    {
        self::assertEquals(
            [new ForgeBranch('intakt/7-old', new CommitId($this->head), false, false, false), new ForgeBranch('main', new CommitId($this->main), true, false, false)],
            $this->forge()->branchesOf('widgets'),
        );
    }

    public function testABranchIsDeletedWhileStillAtTheListedHead(): void
    {
        $this->forge()->deleteBranch('widgets', new ForgeBranch('intakt/7-old', new CommitId($this->head), false, false, false));

        self::assertSame('', $this->git('for-each-ref', 'refs/heads/intakt/7-old'));
    }

    public function testADeleteLeavesNoHeadLockBehind(): void
    {
        $this->forge()->deleteBranch('widgets', new ForgeBranch('intakt/7-old', new CommitId($this->head), false, false, false));

        self::assertFileDoesNotExist($this->repository->path.'/.git/HEAD.lock');
    }

    public function testABranchThatMovedSinceItWasListedIsNotDeleted(): void
    {
        try {
            $this->forge()->deleteBranch('widgets', new ForgeBranch('intakt/7-old', new CommitId($this->main), false, false, false));
            self::fail('A moved branch was deleted.');
        } catch (ForgeWriteFailed) {
            self::assertSame($this->head, $this->git('rev-parse', 'refs/heads/intakt/7-old'));
        }
    }

    public function testAProjectWithAGitHubRepositoryIsNotServedLocally(): void
    {
        $forge = new LocalForgeBranches(['widgets' => ['repository' => $this->repository->path, 'github' => 'acme/widgets']]);

        self::assertFalse($forge->servesProject('widgets'));
        $this->expectException(ForgeReadFailed::class);
        $forge->branchesOf('widgets');
    }

    public function testADeleteThroughAnAliasOfMainLeavesMainInPlace(): void
    {
        $this->git('symbolic-ref', 'refs/heads/intakt/alias', 'refs/heads/main');

        $this->forge()->deleteBranch('widgets', new ForgeBranch('intakt/alias', new CommitId($this->main), false, false, false));

        self::assertSame($this->main, $this->git('rev-parse', 'refs/heads/main'));
        self::assertSame('', $this->git('for-each-ref', 'refs/heads/intakt/alias'));
    }

    public function testASymbolicBranchIsNeverListed(): void
    {
        $this->git('symbolic-ref', 'refs/heads/intakt/alias', 'refs/heads/main');

        self::assertSame(['intakt/7-old', 'main'], array_map(static fn (ForgeBranch $branch): string => $branch->name, $this->forge()->branchesOf('widgets')));
    }

    public function testABranchCheckedOutAfterTheListingIsNotDeleted(): void
    {
        $listed = $this->forge()->branchesOf('widgets')[0];
        $this->repository->checkout('intakt/7-old');

        try {
            $this->forge()->deleteBranch('widgets', $listed);
            self::fail('A checked out branch was deleted.');
        } catch (ForgeWriteFailed) {
            self::assertSame($this->head, $this->git('rev-parse', 'refs/heads/intakt/7-old'));
        }
    }

    public function testABranchCheckedOutInALinkedWorktreeIsNotDeleted(): void
    {
        $this->git('worktree', 'add', $this->repository->path.'/linked', 'intakt/7-old');

        try {
            $this->forge()->deleteBranch('widgets', new ForgeBranch('intakt/7-old', new CommitId($this->head), false, false, false));
            self::fail('A branch checked out in a linked worktree was deleted.');
        } catch (ForgeWriteFailed) {
            self::assertSame($this->head, $this->git('rev-parse', 'refs/heads/intakt/7-old'));
        }
    }

    public function testACheckoutBetweenTheCheckAndTheDeleteNeverLeavesHeadOnADeletedBranch(): void
    {
        $git = trim(new Process(['sh', '-c', 'command -v git'])->mustRun()->getOutput());
        $tools = $this->repository->path.'/.git/test-tools';
        mkdir($tools);
        // The checkout runs right after the forge reads the checked out branches, whatever its outcome, because that is the order a concurrent checkout races in.
        file_put_contents($tools.'/git', sprintf("#!/bin/sh\nfor arg do\n  if [ \"\$arg\" = worktree ]; then\n    %1\$s \"\$@\"; status=\$?\n    %1\$s switch --quiet intakt/7-old 2>/dev/null\n    exit \$status\n  fi\ndone\nexec %1\$s \"\$@\"\n", escapeshellarg($git)));
        chmod($tools.'/git', 0o700);
        $path = (string) getenv('PATH');
        putenv('PATH='.$tools.':'.$path);

        try {
            $this->forge()->deleteBranch('widgets', new ForgeBranch('intakt/7-old', new CommitId($this->head), false, false, false));
        } catch (ForgeWriteFailed) {
            // A refused delete is safe as well, because only a HEAD left on a missing branch loses the worktree's place.
        } finally {
            putenv('PATH='.$path);
        }

        self::assertSame(0, new Process(['git', '-C', $this->repository->path, 'rev-parse', '--verify', '--quiet', $this->git('symbolic-ref', 'HEAD')])->run(), 'HEAD names a branch that no longer exists.');
    }

    public function testABranchIsNotDeletedWhileAnotherGitCommandHoldsTheHeadLock(): void
    {
        touch($this->repository->path.'/.git/HEAD.lock');

        try {
            $this->forge()->deleteBranch('widgets', new ForgeBranch('intakt/7-old', new CommitId($this->head), false, false, false));
            self::fail('A branch was deleted while HEAD was locked.');
        } catch (ForgeWriteFailed) {
            self::assertSame($this->head, $this->git('rev-parse', 'refs/heads/intakt/7-old'));
            self::assertFileExists($this->repository->path.'/.git/HEAD.lock');
        }
    }

    public function testARefusedDeleteLeavesNoHeadLockBehind(): void
    {
        $this->git('worktree', 'add', $this->repository->path.'/linked', 'intakt/7-old');

        try {
            $this->forge()->deleteBranch('widgets', new ForgeBranch('intakt/7-old', new CommitId($this->head), false, false, false));
            self::fail('A branch checked out in a linked worktree was deleted.');
        } catch (ForgeWriteFailed) {
            self::assertSame([], [...glob($this->repository->path.'/.git/HEAD.lock') ?: [], ...glob($this->repository->path.'/.git/worktrees/*/HEAD.lock') ?: []]);
        }
    }

    public function testACheckedOutBranchIsNotDeletedWhenATagSharesItsName(): void
    {
        $this->repository->checkout('intakt/7-old');
        $this->repository->tag('intakt/7-old');

        try {
            $this->forge()->deleteBranch('widgets', new ForgeBranch('intakt/7-old', new CommitId($this->head), false, false, false));
            self::fail('A checked out branch was deleted.');
        } catch (ForgeWriteFailed) {
            self::assertSame($this->head, $this->git('rev-parse', 'refs/heads/intakt/7-old'));
        }
    }

    public function testACheckedOutBranchSharingItsNameWithATagIsTheDefault(): void
    {
        $this->repository->tag('main');

        self::assertSame(['main'], array_values(array_map(static fn (ForgeBranch $branch): string => $branch->name, array_filter($this->forge()->branchesOf('widgets'), static fn (ForgeBranch $branch): bool => $branch->isDefault))));
    }

    public function testAMissingDefaultRefFailsTheCommitReadInsteadOfNamingAPath(): void
    {
        $this->repository->commitFile('refs/heads/unborn', "path\n");
        $this->git('symbolic-ref', 'HEAD', 'refs/heads/unborn');

        $this->expectException(ForgeReadFailed::class);

        $this->forge()->hasCommitByPerson('widgets', new ForgeBranch('intakt/7-old', new CommitId($this->head), false, false, false));
    }

    public function testACommitWithAnEmptyAuthorEmailIsAPersons(): void
    {
        $this->repository->checkoutNewBranch('intakt/9-anonymous');
        $head = $this->repository->commitObject(sprintf("tree %s\nparent %s\nauthor Someone <> 1700000000 +0000\ncommitter Someone <> 1700000000 +0000\n\nanonymous\n", $this->git('rev-parse', $this->main.'^{tree}'), $this->main));
        $this->repository->checkout('main');

        self::assertTrue($this->forge()->hasCommitByPerson('widgets', new ForgeBranch('intakt/9-anonymous', new CommitId($head), false, false, false)));
    }

    public function testAReleaseBranchIsReportedAsOne(): void
    {
        $this->git('branch', 'release/1.x', $this->main);

        $branches = array_column(array_map(static fn (ForgeBranch $branch): array => [$branch->name, $branch->isRelease], $this->forge()->branchesOf('widgets')), 1, 0);

        self::assertSame(['intakt/7-old' => false, 'main' => false, 'release/1.x' => true], $branches);
    }

    public function testABranchWithACommitByAPersonHasOne(): void
    {
        self::assertTrue($this->forge()->hasCommitByPerson('widgets', new ForgeBranch('intakt/7-old', new CommitId($this->head), false, false, false)));
    }

    public function testABranchOfOnlyIntaktsCommitsHasNoneByAPerson(): void
    {
        $this->repository->checkoutNewBranch('intakt/8-new');
        $head = $this->repository->commitFile('c.txt', "three\n");
        $this->git('-c', 'user.name=Intakt', '-c', 'user.email='.LocalForgeBranches::INTAKT_EMAIL, 'commit', '--amend', '--no-edit', '--reset-author');
        $this->repository->checkout('main');
        $amended = $this->git('rev-parse', 'refs/heads/intakt/8-new');

        self::assertNotSame($head, $amended);
        self::assertFalse($this->forge()->hasCommitByPerson('widgets', new ForgeBranch('intakt/8-new', new CommitId($amended), false, false, false)));
    }

    public function testACommitReadThatFailsThrowsInsteadOfMeaningNoPerson(): void
    {
        $this->repository->checkoutDetached($this->main);

        $this->expectException(ForgeReadFailed::class);

        $this->forge()->hasCommitByPerson('widgets', new ForgeBranch('intakt/7-old', new CommitId($this->head), false, false, false));
    }

    public function testAProjectHasNoPullRequestsOnTheLocalForge(): void
    {
        self::assertNull($this->forge()->changeOf('widgets', new ForgeBranch('intakt/7-old', new CommitId($this->head), false, false, false)));
    }

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->main = $this->repository->commitFile('a.txt', "one\n");
        $this->repository->checkoutNewBranch('intakt/7-old');
        $this->head = $this->repository->commitFile('b.txt', "two\n");
        $this->repository->checkout('main');
    }

    protected function tearDown(): void
    {
        $this->repository->remove();
    }

    private function forge(): LocalForgeBranches
    {
        return new LocalForgeBranches(['widgets' => ['repository' => $this->repository->path]]);
    }

    private function git(string ...$arguments): string
    {
        $process = new Process(['git', '-C', $this->repository->path, ...$arguments]);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
