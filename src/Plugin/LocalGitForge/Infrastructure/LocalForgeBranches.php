<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\Infrastructure;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\Watcher\Contract\WatcherServices;
use Intakt\Plugin\Forge\Contract\BranchChange;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\ForgeBranch;
use Intakt\Plugin\Forge\Contract\ForgeBranches;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

// A local repository has no pull requests and no branch protection, so every branch here is judged by its head and its commits alone.
final readonly class LocalForgeBranches implements ForgeBranches
{
    private const int TIMEOUT_SECONDS = 60;
    private const string HEADS = 'refs/heads/';
    // The author email of Intakt's own commits in a local repository; any other author is a person.
    public const string INTAKT_EMAIL = 'intakt@localhost';

    /**
     * @param array<string, array{repository: string, github?: string}> $projects
     */
    public function __construct(
        #[Autowire(param: WatcherServices::PROJECTS)]
        private array $projects,
        private HardenedGit $hardened = new HardenedGit(),
        private string $intaktEmail = self::INTAKT_EMAIL,
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
        // A symbolic branch is left out, because it only names another branch and deleting through it could take that one.
        $listed = $this->read($repository, ['for-each-ref', '--format=%(objectname) %(symref:short) %(refname:strip=2)', self::HEADS]);

        return array_values(array_map(static function (string $line) use ($default): ForgeBranch {
            [$head, , $name] = explode(' ', $line, 3);

            return new ForgeBranch($name, new CommitId($head), $name === $default, false, ForgeBranch::isReleaseName($name));
        }, array_filter('' === $listed ? [] : explode("\n", $listed), static fn (string $line): bool => 1 === preg_match('/^\S+  \S/', $line))));
    }

    public function changeOf(string $project, ForgeBranch $branch): ?BranchChange
    {
        return null;
    }

    public function deleteBranch(string $project, ForgeBranch $branch): void
    {
        $repository = $this->repositoryOf($project);
        if ($this->defaultOf($repository) === $branch->name) {
            throw new ForgeWriteFailed(sprintf('The branch "%s" is checked out, so it was not deleted.', $branch->name));
        }

        // No dereference and the old value, because a symbolic branch must never take its target with it and a branch that moved since it was listed is refused by git.
        $this->write($repository, ['update-ref', '-d', '--no-deref', self::HEADS.$branch->name, $branch->head->hash], sprintf('the branch "%s" was not deleted, it may have moved', $branch->name));
    }

    public function hasCommitByPerson(string $project, ForgeBranch $branch): bool
    {
        $repository = $this->repositoryOf($project);
        $default = $this->defaultOf($repository) ?? throw new ForgeReadFailed('The local repository has no checked out branch to compare against.');
        $authors = $this->read($repository, ['log', '--format=%ae', $branch->head->hash, '--not', self::HEADS.$default]);

        return array_any('' === $authors ? [] : explode("\n", $authors), fn (string $email): bool => $this->intaktEmail !== $email);
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
