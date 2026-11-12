<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\CheckHandBack;

use Intakt\Core\HandBack\Contract\HandBackNotChecked;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// What the build was given, main and the task's branch as pushed (null before its first push), read per check, so the container has no reason to hold it.
#[Exclude]
final readonly class GivenHistory
{
    public function __construct(
        public RepositoryGit $git,
        public string $repository,
        public string $main,
        public string $branch,
        public ?string $pushed,
    ) {
    }

    public function withObjectsIn(string $quarantine): self
    {
        return new self($this->git->withObjectsIn($quarantine), $this->repository, $this->main, $this->branch, $this->pushed);
    }

    public function holds(string $commit): bool
    {
        return (null !== $this->pushed && $this->isAncestor($commit, $this->pushed)) || $this->isOnMain($commit);
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

    /**
     * The commits the head reaches that main and the pushed branch do not, or null when one of them is missing.
     *
     * @return list<string>|null
     */
    public function reachedCommits(string $head): ?array
    {
        return $this->reached([$head]);
    }

    /**
     * The commits, trees and blobs the head reaches that main and the pushed branch do not, or null when one of them is missing, because unbundling checks no connectivity.
     *
     * @return list<string>|null
     */
    public function reachedObjects(string $head): ?array
    {
        return $this->reached(['--objects', $head]);
    }

    public function isFastForwardTo(string $head): bool
    {
        // Because merge-base peels an annotated tag to its commit, and a branch can only take a commit.
        if ('commit' !== $this->git->answer($this->repository, ['cat-file', '-t', $head])) {
            return false;
        }

        // A first build may start on any commit of main, because main can move in the repository while the build runs; a new root commit starts elsewhere, also behind a merge of main.
        return null === $this->pushed
            ? '' === $this->git->answer($this->repository, ['rev-list', '--max-parents=0', '--max-count=1', $head, '--not', $this->main])
            : $this->isAncestor($this->pushed, $head);
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
     * @param list<string> $revisions
     *
     * @return list<string>|null
     */
    private function reached(array $revisions): ?array
    {
        $output = $this->git->answer($this->repository, ['rev-list', ...$revisions, '--not', ...$this->known()]);

        if (null === $output || '' === $output) {
            return null === $output ? null : [];
        }

        return array_map(static fn (string $line): string => explode(' ', $line, 2)[0], explode("\n", $output));
    }

    /**
     * @return non-empty-list<string>
     */
    private function known(): array
    {
        return null === $this->pushed ? [$this->main] : [$this->pushed, $this->main];
    }

    /**
     * @return list<non-empty-list<string>>
     *
     * @throws HandBackNotChecked
     */
    private function newCommitsWithParents(string $head, string $option): array
    {
        // Because a merge of main brings main's commits in as they are, they are never new.
        $output = $this->git->output($this->repository, ['rev-list', '--topo-order', '--parents', $option, $head, '--not', ...$this->known()]);

        return '' === $output ? [] : array_map(static fn (string $line): array => explode(' ', $line), explode("\n", $output));
    }
}
