<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge\Infrastructure;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\GitCommand;
use Intakt\Core\Git\Contract\GitEnvironment;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Plugin\Forge\Contract\BranchRestore;
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
#[UsesClass(BranchRestore::class)]
#[UsesClass(CommitId::class)]
final class LocalForgeBranchesTest extends TestCase
{
    private TemporaryRepository $repository;
    private string $main;
    private string $head;

    public function testTheBranchesAreListedWithTheCheckedOutOneAsTheDefaultAndNoneProtected(): void
    {
        self::assertEquals(
            [new ForgeBranch('intakt/7-old', new CommitId($this->head), false, false), new ForgeBranch('main', new CommitId($this->main), true, false)],
            $this->forge()->branchesOf('widgets'),
        );
    }

    public function testABranchIsDeletedWhileStillAtTheListedHead(): void
    {
        $this->forge()->deleteBranch('widgets', new ForgeBranch('intakt/7-old', new CommitId($this->head), false, false));

        self::assertSame('', $this->git('for-each-ref', 'refs/heads/intakt/7-old'));
    }

    public function testABranchThatMovedSinceItWasListedIsNotDeleted(): void
    {
        try {
            $this->forge()->deleteBranch('widgets', new ForgeBranch('intakt/7-old', new CommitId($this->main), false, false));
            self::fail('A moved branch was deleted.');
        } catch (ForgeWriteFailed) {
            self::assertSame($this->head, $this->git('rev-parse', 'refs/heads/intakt/7-old'));
        }
    }

    public function testARestoreNeverOverwritesABranchThatExistsAgain(): void
    {
        $this->expectException(ForgeWriteFailed::class);

        $this->forge()->restoreBranch('widgets', new BranchRestore('intakt/7-old', new CommitId($this->main), 'refs/heads/main', $this->repository->path));
    }

    public function testAProjectWithAGitHubRepositoryIsNotServedLocally(): void
    {
        $forge = new LocalForgeBranches(['widgets' => ['repository' => $this->repository->path, 'github' => 'acme/widgets']]);

        self::assertFalse($forge->servesProject('widgets'));
        $this->expectException(ForgeReadFailed::class);
        $forge->branchesOf('widgets');
    }

    public function testAProjectHasNoPullRequestsOnTheLocalForge(): void
    {
        self::assertNull($this->forge()->changeOf('widgets', new ForgeBranch('intakt/7-old', new CommitId($this->head), false, false)));
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
