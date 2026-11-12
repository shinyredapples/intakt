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

    // Every path on both sides, never a rename, and every submodule entry, whatever the repository's own settings ask to ignore.
    private const array RAW_DIFF = ['diff-tree', '-r', '-z', '--raw', '--no-renames', '--ignore-submodules=none'];

    // Merge-base exits with 1 and prints nothing when two commits share no history.
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
        $main = trim($process->getOutput());

        // Main is the branch the repository's HEAD names, because the mirror's HEAD follows the remote's default branch.
        if (!$process->isSuccessful() || !str_starts_with($main, self::BRANCHES)) {
            throw ChangeNotRead::because('the repository\'s HEAD names no branch, so main is unknown');
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
            foreach ($this->comparedParents($repository, $main, $parents) as $parent) {
                $changed = [...$changed, ...$this->between($repository, $parent, $commit)];
            }
        }

        return $changed;
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
     * @param list<string> $parents
     *
     * @return list<string>
     *
     * @throws RepositoryAttributesRefused
     */
    private function comparedParents(string $repository, string $main, array $parents): array
    {
        $onMain = array_values(array_filter($parents, fn (string $parent): bool => $this->run($repository, ['merge-base', '--is-ancestor', $parent, $main])->isSuccessful()));

        // What a commit keeps from a parent main holds is main's own content, so a merge of main lists only what differs from main; a parent that cannot be placed counts as off main, which compares more.
        return [] === $onMain ? $parents : $onMain;
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
        $call = $this->hardened->call(['--git-dir='.$repository, ...$arguments], $repository);

        // Because a work tree beside --git-dir would make git treat the bare mirror as a checkout; empty input, because only hash-object reads it.
        $process = new Process($call->command, $call->directory, [...$call->environment, 'GIT_WORK_TREE' => false], '');
        $process->run();

        return $process;
    }
}
