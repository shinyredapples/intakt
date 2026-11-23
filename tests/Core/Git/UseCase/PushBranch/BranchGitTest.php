<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\PushBranch;

use Closure;
use Intakt\Core\Git\Contract\BranchNotPushed;
use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\UseCase\PushBranch\BranchGit;
use Intakt\Core\Git\UseCase\PushBranch\GuardedRun;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BranchGit::class)]
#[CoversClass(GuardedRun::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(BranchNotPushed::class)]
#[UsesClass(TemporaryRepository::class)]
final class BranchGitTest extends TestCase
{
    private TemporaryRepository $repository;
    private ProgramOnPath $programs;
    private string $credential;
    private string $remote;

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function remoteCalls(): iterable
    {
        yield 'the push' => ['push', ['push', '--quiet', '--', '%s', 'HEAD:refs/heads/task']];
        yield 'the read of the remote branch' => ['ls-remote', ['ls-remote', '--', '%s', 'refs/heads/task']];
    }

    /**
     * @param list<string> $arguments
     */
    #[DataProvider('remoteCalls')]
    public function testARemoteCallKilledByASignalIsNotPushedAndNamesNoCredentialOfTheRemote(string $command, array $arguments): void
    {
        $this->programs->killOn('git', $command);

        $stopped = $this->stopped(fn (): string => new BranchGit(new HardenedGit(), new GuardedRun())->outputFromRemote($this->gitDirectory(), $this->toTheRemote($arguments)));

        self::assertSame(sprintf('The branch was not pushed: git %s was killed by signal 15', $command), $stopped->getMessage());
        self::assertNull($stopped->getPrevious());
    }

    /**
     * @param list<string> $arguments
     */
    #[DataProvider('remoteCalls')]
    public function testARemoteCallThatHangsPastTheLimitIsNotPushedAndNamesNoCredentialOfTheRemote(string $command, array $arguments): void
    {
        $this->programs->hangOn('git', $command);

        $stopped = $this->stopped(fn (): string => new BranchGit(new HardenedGit(), new GuardedRun(), 0.2)->outputFromRemote($this->gitDirectory(), $this->toTheRemote($arguments)));

        self::assertSame(sprintf('The branch was not pushed: git %s timed out after 0.2 seconds', $command), $stopped->getMessage());
        self::assertStringNotContainsString($this->credential, $stopped->getMessage());
        self::assertNull($stopped->getPrevious());
    }

    public function testACallOnTheRepositoryKilledByASignalIsNotPushed(): void
    {
        $this->programs->killOn('git', 'cat-file');

        $stopped = $this->stopped(fn (): string => new BranchGit(new HardenedGit(), new GuardedRun())->output($this->gitDirectory(), ['cat-file', 'commit', 'HEAD']));

        self::assertSame('The branch was not pushed: git cat-file was killed by signal 15', $stopped->getMessage());
        self::assertNull($stopped->getPrevious());
    }

    public function testATestMergeKilledByASignalIsNotPushed(): void
    {
        $this->programs->killOn('git', 'merge-tree');

        $stopped = $this->stopped(fn (): ?array => new BranchGit(new HardenedGit(), new GuardedRun())->mergeTree($this->gitDirectory(), 'HEAD', 'HEAD'));

        self::assertSame('The branch was not pushed: git merge-tree was killed by signal 15', $stopped->getMessage());
        self::assertNull($stopped->getPrevious());
    }

    public function testACallInARepositoryFolderThatIsGoneCannotStartAndIsNotPushed(): void
    {
        $gone = $this->repository->path.'/gone';

        $stopped = $this->stopped(static fn (): string => new BranchGit(new HardenedGit(), new GuardedRun())->lines($gone, ['symbolic-ref', '--quiet', 'HEAD']));

        self::assertSame('The branch was not pushed: git symbolic-ref could not start', $stopped->getMessage());
        self::assertNull($stopped->getPrevious());
    }

    public function testACallStoppedWhileItsRepositoryIsLookedAtIsNotPushed(): void
    {
        $this->programs->killOn('git', 'rev-parse');

        $stopped = $this->stopped(fn (): string => new BranchGit(new HardenedGit(), new GuardedRun())->lines($this->gitDirectory(), ['symbolic-ref', '--quiet', 'HEAD']));

        self::assertSame('The branch was not pushed: git symbolic-ref could not be prepared, because the look at its repository was stopped or found no bare repository', $stopped->getMessage());
        self::assertNull($stopped->getPrevious());
    }

    public function testACallThatEndsWithinTheLimitGivesGitsOutput(): void
    {
        $this->programs->hangOn('git', 'push');

        self::assertSame('refs/heads/main', new BranchGit(new HardenedGit(), new GuardedRun(), 30)->lines($this->gitDirectory(), ['symbolic-ref', '--quiet', 'HEAD']));
    }

    public function testACallThatGitRefusesKeepsItsOwnReason(): void
    {
        $stopped = $this->stopped(fn (): string => new BranchGit(new HardenedGit(), new GuardedRun())->outputFromRemote($this->gitDirectory(), ['ls-remote', '--', $this->repository->path.'/no-remote', 'refs/heads/task']));

        self::assertSame('The branch was not pushed: git ls-remote failed with exit code 128', $stopped->getMessage());
    }

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('a.txt', "one\n");
        $this->programs = new ProgramOnPath();
        $this->credential = 'not-a-real-'.bin2hex(random_bytes(4));
        $this->remote = 'https://intakt:'.$this->credential.'@localhost/repo.git';
    }

    protected function tearDown(): void
    {
        $this->programs->remove();
        $this->repository->remove();
    }

    private function gitDirectory(): string
    {
        return $this->repository->path.'/.git';
    }

    /**
     * @param list<string> $arguments
     *
     * @return list<string>
     */
    private function toTheRemote(array $arguments): array
    {
        return array_map(fn (string $argument): string => sprintf($argument, $this->remote), $arguments);
    }

    /**
     * @param Closure(): mixed $call
     */
    private function stopped(Closure $call): BranchNotPushed
    {
        try {
            $call();
        } catch (BranchNotPushed $stopped) {
            self::assertStringNotContainsString($this->credential, $stopped->getMessage());

            return $stopped;
        }

        self::fail('The call must end as a branch that was not pushed.');
    }
}
