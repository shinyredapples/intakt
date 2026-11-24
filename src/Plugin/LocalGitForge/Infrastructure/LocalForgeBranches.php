<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\Infrastructure;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\Watcher\Contract\WatcherServices;
use Intakt\Plugin\Forge\Contract\BranchChange;
use Intakt\Plugin\Forge\Contract\BranchRestore;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\ForgeBranch;
use Intakt\Plugin\Forge\Contract\ForgeBranches;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

// A watched project without a GitHub repository lives in its local repository, which has no pull requests and no branch protection.
final readonly class LocalForgeBranches implements ForgeBranches
{
    private const int TIMEOUT_SECONDS = 60;
    private const string HEADS = 'refs/heads/';

    /**
     * @param array<string, array{repository: string, github?: string}> $projects
     */
    public function __construct(
        #[Autowire(param: WatcherServices::PROJECTS)]
        private array $projects,
        private HardenedGit $hardened = new HardenedGit(),
    ) {
    }

    public function servesProject(string $project): bool
    {
        return isset($this->projects[$project]) && !isset($this->projects[$project]['github']);
    }

    public function branchesOf(string $project): array
    {
        $repository = $this->repositoryOf($project);
        $default = $this->defaultOf($repository);
        $listed = $this->read($repository, ['for-each-ref', '--format=%(objectname) %(refname:strip=2)', self::HEADS]);

        return array_map(static function (string $line) use ($default): ForgeBranch {
            [$head, $name] = explode(' ', $line, 2);

            return new ForgeBranch($name, new CommitId($head), $name === $default, false);
        }, '' === $listed ? [] : explode("\n", $listed));
    }

    public function changeOf(string $project, ForgeBranch $branch): ?BranchChange
    {
        return null;
    }

    public function deleteBranch(string $project, ForgeBranch $branch): void
    {
        // The old value makes git delete only while the branch is still at the listed head.
        $this->write($this->repositoryOf($project), ['update-ref', '-d', self::HEADS.$branch->name, $branch->head->hash], sprintf('the branch "%s" was not deleted, it may have moved', $branch->name));
    }

    public function restoreBranch(string $project, BranchRestore $restore): void
    {
        $repository = $this->repositoryOf($project);
        // Fetched without a ref of its own, because only the commit's objects are needed before the branch points at it.
        $this->fetchFromMirror($repository, $restore);
        $this->write($repository, ['update-ref', self::HEADS.$restore->branch, $restore->head->hash, ''], sprintf('the branch "%s" was not restored, it exists again', $restore->branch));
    }

    // Only the file protocol, because HardenedGit allows none and the mirror is a folder on this machine.
    private function fetchFromMirror(string $repository, BranchRestore $restore): void
    {
        $fetch = $this->call($repository, ['fetch', '--quiet', '--no-write-fetch-head', '--', $restore->mirror, $restore->archiveRef]);
        if (!$this->ran(new GitCall($fetch->command, $fetch->directory, $fetch->environment->with(GIT_ALLOW_PROTOCOL: 'file')))->isSuccessful()) {
            throw new ForgeWriteFailed(sprintf('The archived head of "%s" was not fetched from the mirror.', $restore->branch));
        }
    }

    private function repositoryOf(string $project): string
    {
        return $this->servesProject($project) ? $this->projects[$project]['repository'] : throw new ForgeReadFailed(sprintf('Project "%s" is not watched in a local repository.', $project));
    }

    private function defaultOf(string $repository): ?string
    {
        $process = $this->git($repository, ['symbolic-ref', '--quiet', '--short', 'HEAD']);

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }

    /**
     * @param list<string> $arguments
     */
    private function read(string $repository, array $arguments): string
    {
        $process = $this->git($repository, $arguments);

        return $process->isSuccessful() ? trim($process->getOutput()) : throw new ForgeReadFailed(sprintf('git %s failed in the local repository.', $arguments[0]));
    }

    /**
     * @param list<string> $arguments
     */
    private function write(string $repository, array $arguments, string $failure): void
    {
        if (!$this->git($repository, $arguments)->isSuccessful()) {
            throw new ForgeWriteFailed(ucfirst($failure).'.');
        }
    }

    /**
     * @param list<string> $arguments
     */
    private function git(string $repository, array $arguments): Process
    {
        return $this->ran($this->call($repository, $arguments));
    }

    /**
     * @param list<string> $arguments
     */
    private function call(string $repository, array $arguments): GitCall
    {
        try {
            return $this->hardened->call($repository, ...$arguments);
        } catch (RepositoryAttributesRefused $refused) {
            throw new ForgeReadFailed(sprintf('git %s did not run: %s', $arguments[0], $refused->getMessage()), $refused->getCode(), previous: $refused);
        }
    }

    private function ran(GitCall $call): Process
    {
        try {
            $process = new Process([...$call->command], $call->directory, [...$call->environment], timeout: self::TIMEOUT_SECONDS);
            $process->run();
        } catch (ExceptionInterface $failure) {
            throw new ForgeReadFailed(sprintf('git did not run: %s', $failure->getMessage()), $failure->getCode(), previous: $failure);
        }

        return $process;
    }
}
