<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\BranchCleanup\UseCase;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\BranchCleanup\Infrastructure\CleanupPolicies;
use Intakt\Core\BranchCleanup\Infrastructure\ProjectForges;
use Intakt\Core\BranchCleanup\UseCase\CleanUpBranches\BranchWarnings;
use Intakt\Core\BranchCleanup\UseCase\CleanUpBranches\ProjectCleanup;
use Intakt\Core\BranchCleanup\UseCase\RestoreBranch\RestoreBranch;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\NetworkGit;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\UseCase\ArchiveBranch\MirrorBranchArchive;
use Intakt\Core\Git\UseCase\PushBranch\BranchGit;
use Intakt\Core\Git\UseCase\PushBranch\GuardedRun;
use Intakt\Core\Git\UseCase\PushBranch\SignedCommits;
use Intakt\Core\Git\UseCase\PushBranch\SigningKey;
use Intakt\Core\Git\UseCase\UpdateMirror\UpdateMirror;
use Intakt\Core\HandBack\Contract\AcceptedHandBack;
use Intakt\Plugin\Forge\Contract\BranchChange;
use Intakt\Plugin\Forge\Contract\BranchRestore;
use Intakt\Plugin\Forge\Contract\ForgeBranch;
use Intakt\Plugin\Forge\Contract\ForgeBranches;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalForgeBranches;
use Intakt\Tests\Core\Git\RecordingClock;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Core\Git\UseCase\PushBranch\PushScenario;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Process\Process;

// A project on the local forge in a temporary repository, its mirror and its warnings on SQLite, with a clock the test moves; pull requests are scripted per branch, because the local forge has none.
final class BranchCleanupScenario
{
    public const string PROJECT = 'widgets';

    public readonly TemporaryRepository $remote;
    public readonly MockClock $clock;
    public readonly LockFactory $locks;
    /** @var array<string, BranchChange> */
    public array $changes = [];
    /** @var array<string, array{clean_other_branches: bool, keep_branches: list<string>}> */
    public array $policies = [];
    /** @var array<string, array{repository: string}> */
    public array $projects;
    private readonly string $folder;
    private readonly string $key;
    private readonly string $main;

    public function __construct(
        private readonly EntityManagerInterface $manager,
    ) {
        $this->folder = sys_get_temp_dir().'/intakt-branch-cleanup-'.bin2hex(random_bytes(6));
        mkdir($this->folder);
        $this->key = PushScenario::generateKey($this->folder.'/orchestrator');
        $this->remote = new TemporaryRepository();
        $this->main = $this->remote->commitFile('a.txt', "one\n");
        // An hour after the first commit, because a test's branch commits come seconds later and a day count must not depend on those seconds.
        $this->clock = new MockClock($this->committedAt($this->main)->modify('+1 hour'));
        $this->locks = new LockFactory(new InMemoryStore());
        $this->projects = [self::PROJECT => ['repository' => $this->remote->path]];
    }

    // Signed with the orchestrator's key, as Intakt pushes its commits, so the branch is not claimed by a person.
    public function intaktBranch(string $name, string $file = 'b.txt'): string
    {
        $unsigned = $this->personBranch($name, $file);
        $signed = new SignedCommits($this->branchGit(), new SigningKey($this->key, new Filesystem(), new GuardedRun()))->of($this->remote->path.'/.git', new AcceptedHandBack(null, $unsigned, 0), 'main');
        $this->git('update-ref', 'refs/heads/'.$name, $signed);

        return $signed;
    }

    public function personBranch(string $name, string $file = 'b.txt'): string
    {
        $this->remote->checkoutNewBranch($name);
        $head = $this->remote->commitFile($file, $name."\n");
        $this->remote->checkout('main');

        return $head;
    }

    public function daysLater(int $days): void
    {
        $this->clock->modify(sprintf('+%d days', $days));
    }

    public function cleanup(?LoggerInterface $logger = null): ProjectCleanup
    {
        return new ProjectCleanup($this->forges(), new CleanupPolicies($this->policies), $this->archive(), new BranchWarnings($this->manager), $this->clock, $this->locks, $logger ?? new NullLogger());
    }

    public function restore(): RestoreBranch
    {
        return new RestoreBranch($this->forges(), $this->archive());
    }

    public function forges(): ProjectForges
    {
        return new ProjectForges([$this->scriptedForge()], $this->projects);
    }

    public function headOf(string $branch): string
    {
        return $this->git('for-each-ref', '--format=%(objectname)', 'refs/heads/'.$branch);
    }

    public function mirrorGit(string ...$arguments): string
    {
        return $this->outputOf(['git', '--git-dir='.$this->folder.'/mirrors/'.self::PROJECT.'.git', ...$arguments]);
    }

    public function git(string ...$arguments): string
    {
        return $this->outputOf(['git', '-C', $this->remote->path, ...$arguments]);
    }

    public function remove(): void
    {
        $this->remote->remove();
        new Filesystem()->remove($this->folder);
    }

    private function committedAt(string $commit): DateTimeImmutable
    {
        return new DateTimeImmutable('@'.$this->git('log', '-1', '--format=%ct', $commit));
    }

    private function archive(): MirrorBranchArchive
    {
        $mirrors = new Mirrors($this->folder.'/mirrors');
        $update = new UpdateMirror(new HardenedGit(), new NetworkGit(new RecordingClock(), new NullLogger()), new Filesystem(), new LockFactory(new InMemoryStore()), $mirrors);

        return new MirrorBranchArchive($update, $mirrors, $this->branchGit(), new SignedCommits($this->branchGit(), new SigningKey($this->key, new Filesystem(), new GuardedRun())), $this->clock);
    }

    private function branchGit(): BranchGit
    {
        return new BranchGit(new HardenedGit(), new GuardedRun());
    }

    private function scriptedForge(): ForgeBranches
    {
        $local = new LocalForgeBranches($this->projects);
        $changes = $this->changes;

        return new readonly class($local, $changes) implements ForgeBranches {
            /**
             * @param array<string, BranchChange> $changes
             */
            public function __construct(
                private LocalForgeBranches $local,
                private array $changes,
            ) {
            }

            public function servesProject(string $project): bool
            {
                return $this->local->servesProject($project);
            }

            public function branchesOf(string $project): array
            {
                return $this->local->branchesOf($project);
            }

            public function changeOf(string $project, ForgeBranch $branch): ?BranchChange
            {
                return $this->changes[$branch->name] ?? null;
            }

            public function deleteBranch(string $project, ForgeBranch $branch): void
            {
                $this->local->deleteBranch($project, $branch);
            }

            public function restoreBranch(string $project, BranchRestore $restore): void
            {
                $this->local->restoreBranch($project, $restore);
            }
        };
    }

    /**
     * @param list<string> $command
     */
    private function outputOf(array $command): string
    {
        $process = new Process($command);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
