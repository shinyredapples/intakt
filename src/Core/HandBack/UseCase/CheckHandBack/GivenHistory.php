<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\CheckHandBack;

use Intakt\Core\HandBack\Contract\HandBackNotChecked;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// What the build was given, main and the task's branch as pushed, read per check, so the container has no reason to hold it.
#[Exclude]
final readonly class GivenHistory
{
    public function __construct(
        private RepositoryGit $git,
        public string $repository,
        public string $main,
        public string $branch,
        public string $base,
    ) {
    }

    public function holds(string $commit): bool
    {
        return $this->isAncestor($commit, $this->base) || $this->isOnMain($commit);
    }

    public function isOnMain(string $commit): bool
    {
        return $this->isAncestor($commit, $this->main);
    }

    public function unbundle(string $bundle): bool
    {
        $path = realpath($bundle);

        return false !== $path && $this->git->succeeds($this->repository, ['bundle', 'unbundle', $path]);
    }

    public function isFastForwardTo(string $head): bool
    {
        // Because merge-base peels an annotated tag to its commit, and a branch can only take a commit.
        return 'commit' === $this->git->answer($this->repository, ['cat-file', '-t', $head])
            && $this->isAncestor($this->base, $head);
    }

    /**
     * @return list<list<string>>
     *
     * @throws HandBackNotChecked
     */
    public function newMerges(string $head): array
    {
        return array_map(
            static fn (array $commit): array => array_slice($commit, 1),
            $this->newCommitsWithParents($head, '--min-parents=2'),
        );
    }

    /**
     * @return list<string>
     *
     * @throws HandBackNotChecked
     */
    public function newCommits(string $head): array
    {
        return array_map(static fn (array $commit): string => $commit[0], $this->newCommitsWithParents($head, '--reverse'));
    }

    private function isAncestor(string $commit, string $descendant): bool
    {
        return $this->git->succeeds($this->repository, ['merge-base', '--is-ancestor', $commit, $descendant]);
    }

    /**
     * @return list<non-empty-list<string>>
     *
     * @throws HandBackNotChecked
     */
    private function newCommitsWithParents(string $head, string $option): array
    {
        // Because a merge of main brings main's commits in as they are, they are never new.
        $output = $this->git->output($this->repository, ['rev-list', '--topo-order', '--parents', $option, $head, '^'.$this->base, '^'.$this->main]);

        return '' === $output ? [] : array_map(static fn (string $line): array => explode(' ', $line), explode("\n", $output));
    }
}
