<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\ListProtectedChanges;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\Git\Domain\ChangedPath;
use Intakt\Core\Git\Domain\ChangeNotRead;
use Symfony\Component\Process\Process;

final readonly class ChangedPaths
{
    private const string BRANCHES = 'refs/heads/';

    // No renames, so both sides of a move count, and no ignored submodule, because the repository's own settings must not hide one.
    private const array RAW_DIFF = ['diff-tree', '-r', '-z', '--raw', '--no-renames', '--ignore-submodules=none'];

    // Because merge-base exits with 1 and prints nothing when two commits share no history, which is no failure.
    private const int NO_COMMON_ANCESTOR = 1;

    public function __construct(
        private HardenedGit $hardened,
    ) {
    }

    /**
     * @throws ChangeNotRead
     * @throws RepositoryAttributesRefused
     */
    public function mainOf(string $repository): string
    {
        $process = $this->run($repository, ['symbolic-ref', '--quiet', 'HEAD']);
        $branch = trim($process->getOutput());

        // Main is the branch the repository's HEAD names, because the mirror's HEAD follows the remote's default branch.
        if (!$process->isSuccessful() || !str_starts_with($branch, self::BRANCHES)) {
            throw ChangeNotRead::because('the repository\'s HEAD names no branch, so main is unknown');
        }

        // Resolved once to a commit id, because the branch can move while the check runs (a mirror update), and every later git call must compare against the same main.
        $process = $this->run($repository, ['rev-parse', '--verify', '--quiet', $branch.'^{commit}']);
        $main = trim($process->getOutput());

        if (!$process->isSuccessful() || '' === $main) {
            throw ChangeNotRead::because(sprintf('%s names no commit, so main is unknown', $branch));
        }

        return $main;
    }

    /**
     * @throws ChangeNotRead
     * @throws RepositoryAttributesRefused
     */
    public function mustBeCommit(string $repository, string $head): void
    {
        if ('commit' !== trim($this->run($repository, ['cat-file', '-t', $head])->getOutput())) {
            throw ChangeNotRead::because(sprintf('%s is not a commit of the repository', $head));
        }
    }

    /**
     * @return list<ChangedPath>
     *
     * @throws ChangeNotRead
     * @throws RepositoryAttributesRefused
     */
    public function sinceMergeBase(string $repository, string $main, string $head): array
    {
        $changed = [];
        foreach ($this->mergeBases($repository, $main, $head) as $base) {
            $changed = [...$changed, ...$this->between($repository, $base, $head)];
        }

        return $changed;
    }

    /**
     * @return list<ChangedPath>
     *
     * @throws ChangeNotRead
     * @throws RepositoryAttributesRefused
     */
    public function ofEachCommit(string $repository, string $main, string $head): array
    {
        $changed = [];
        foreach ($this->commitsMainLacks($repository, $main, $head) as [$commit, $parents]) {
            $ofCommit = [];
            foreach ($parents as $parent) {
                $ofCommit = [...$ofCommit, ...$this->between($repository, $parent, $commit)];
            }

            if ([] === $ofCommit) {
                continue;
            }

            // A path the commit holds exactly as the current main does adds nothing to main, so a merge of main does not list what main added; a revert to an old main still differs and stays listed.
            $unlikeMain = array_column($this->between($repository, $main, $commit), 'path', 'path');
            $changed = [...$changed, ...array_filter($ofCommit, static fn (ChangedPath $path): bool => isset($unlikeMain[$path->path]))];
        }

        return $changed;
    }

    /**
     * @throws ChangeNotRead
     * @throws RepositoryAttributesRefused
     */
    public function mustBeComplete(string $repository): void
    {
        // Because a shallow history lacks the commits that would show a protected change undone later, so their absence would read as no change.
        if ('false' !== trim($this->mustSucceed($this->run($repository, ['rev-parse', '--is-shallow-repository']), 'rev-parse'))) {
            throw ChangeNotRead::because('the repository\'s history is shallow, so commits are missing');
        }
    }

    /**
     * @return list<string>
     *
     * @throws ChangeNotRead
     * @throws RepositoryAttributesRefused
     */
    private function mergeBases(string $repository, string $main, string $head): array
    {
        $process = $this->run($repository, ['merge-base', '--all', $main, $head]);
        $output = trim($process->getOutput());

        // Without a common ancestor the whole head is the change; every base of a criss-cross history counts, so no choice of one hides a change.
        if (self::NO_COMMON_ANCESTOR === $process->getExitCode() && '' === $output) {
            return [$this->emptyTree($repository)];
        }

        return explode("\n", trim($this->mustSucceed($process, 'merge-base')));
    }

    /**
     * @return list<array{string, list<string>}>
     *
     * @throws ChangeNotRead
     * @throws RepositoryAttributesRefused
     */
    private function commitsMainLacks(string $repository, string $main, string $head): array
    {
        // Every commit the head reaches and main does not, second parents of a merge included, so a merge of main brings nothing in as new.
        $output = trim($this->mustSucceed($this->run($repository, ['rev-list', '--parents', $head, '--not', $main]), 'rev-list'));

        if ('' === $output) {
            return [];
        }

        $commits = [];
        foreach (explode("\n", $output) as $line) {
            $parents = explode(' ', $line);
            $commit = array_shift($parents);
            // A root commit is compared with the empty tree, so everything it adds counts.
            $commits[] = [$commit, [] === $parents ? [$this->emptyTree($repository)] : $parents];
        }

        return $commits;
    }

    /**
     * @return list<ChangedPath>
     *
     * @throws ChangeNotRead
     * @throws RepositoryAttributesRefused
     */
    private function between(string $repository, string $from, string $to): array
    {
        return ChangedPath::listFrom($this->mustSucceed($this->run($repository, [...self::RAW_DIFF, $from, $to]), 'diff-tree'));
    }

    /**
     * @throws ChangeNotRead
     * @throws RepositoryAttributesRefused
     */
    private function emptyTree(string $repository): string
    {
        // Asked from git, because its name depends on the repository's object format.
        return trim($this->mustSucceed($this->run($repository, ['hash-object', '-t', 'tree', '--stdin']), 'hash-object'));
    }

    /**
     * @throws ChangeNotRead
     */
    private function mustSucceed(Process $process, string $command): string
    {
        if (!$process->isSuccessful()) {
            throw ChangeNotRead::because(sprintf('git %s failed: %s', $command, trim($process->getErrorOutput())));
        }

        return $process->getOutput();
    }

    /**
     * @param list<string> $arguments
     *
     * @throws RepositoryAttributesRefused
     */
    private function run(string $repository, array $arguments): Process
    {
        $call = $this->hardened->callInBareRepository($repository, ...$arguments);

        // Empty input, because only hash-object reads it.
        $process = new Process([...$call->command], $call->directory, [...$call->environment], '');
        $process->run();

        return $process;
    }
}
