<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\BranchChange;
use Intakt\Plugin\Forge\Contract\BranchChangeState;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\CommitIdMalformed;
use Intakt\Plugin\Forge\Contract\ForgeBranch;
use Intakt\Plugin\Forge\Contract\ForgeBranches;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;

// Every answer is checked field by field, because a branch read wrongly could be deleted; a doubt fails the read and cleanup deletes nothing.
final readonly class GitHubForgeBranches implements ForgeBranches
{
    public function __construct(
        private RepositoryAddress $repository,
        private GitHubBranchCalls $calls,
    ) {
    }

    public function branches(): array
    {
        $default = $this->defaultBranch();

        return array_map(fn (mixed $branch): ForgeBranch => $this->branchOf($branch, $default), $this->calls->listing($this->repository->path().'/branches', []));
    }

    public function changeOf(ForgeBranch $branch): ?BranchChange
    {
        $pulls = array_map($this->pullOf(...), $this->calls->listing($this->repository->path().'/pulls', ['state' => 'all', 'head' => $this->repository->headOf($branch->name), 'sort' => 'created', 'direction' => 'desc']));
        // An open pull request always counts, and a closed one only for the branch's head, because a pull request closed before later commits says nothing about them.
        $deciding = array_find($pulls, static fn (array $pull): bool => BranchChangeState::Open === $pull['state'])
            ?? array_find($pulls, static fn (array $pull): bool => $pull['head'] === $branch->head->hash);
        // The labels of every pull request of the branch, because a `keep` on an older one still asks to keep it.
        $labels = array_values(array_unique(array_merge([], ...array_column($pulls, 'labels'))));

        return null === $deciding ? null : new BranchChange($deciding['state'], ...$labels);
    }

    public function hasCommitByAPerson(ForgeBranch $branch, string $intaktIdentity): bool
    {
        if ('' === trim($intaktIdentity)) {
            throw new ForgeReadFailed('Intakt\'s identity is empty, so every commit would count as a person\'s.');
        }

        // Compared with the default branch's commit and not its name, because a default branch that advanced between two pages would shift commits onto a page already read.
        $base = $this->headOf($this->calls->object($this->repository->path().'/branches/'.$this->segments($this->defaultBranch())));
        $commits = $this->calls->comparison($this->repository->path().'/compare/'.$base->hash.'...'.$branch->head->hash);
        $intakt = mb_strtolower(trim($intaktIdentity));

        return array_any($commits, fn (mixed $commit): bool => array_any($this->emailsOf($commit), static fn (string $email): bool => mb_strtolower($email) !== $intakt));
    }

    public function delete(ForgeBranch $branch): void
    {
        if (!$branch->isDeletable()) {
            throw new ForgeWriteFailed(sprintf('The branch "%s" is a default, protected, release or symbolic branch, so it was not deleted.', $branch->name));
        }

        try {
            $this->refuseUnlessStillDeletable($branch);
        } catch (ForgeReadFailed $unread) {
            throw new ForgeWriteFailed(sprintf('The branch "%s" was not deleted: %s', $branch->name, $unread->getMessage()), $unread->getCode(), $unread);
        }

        $this->calls->delete($this->repository->path().'/git/refs/heads/'.$this->segments($branch->name));
    }

    private function defaultBranch(): string
    {
        return $this->calls->text($this->calls->object($this->repository->path()), 'default_branch');
    }

    private function branchOf(mixed $branch, string $default): ForgeBranch
    {
        $data = is_array($branch) ? $branch : throw new ForgeReadFailed('GitHub listed a branch that is not an object.');
        $name = $this->calls->text($data, 'name');

        // Never symbolic, because GitHub lists no branch that names another.
        return new ForgeBranch($name, $this->headOf($data), $name === $default, $this->protectedOf($data), false);
    }

    /**
     * @return array{state: BranchChangeState, head: string, labels: list<string>}
     */
    private function pullOf(mixed $pull): array
    {
        $data = is_array($pull) ? $pull : throw new ForgeReadFailed('GitHub listed a pull request that is not an object.');
        $state = $this->calls->text($data, 'state');
        if (!array_key_exists('merged_at', $data)) {
            throw new ForgeReadFailed('GitHub listed a pull request without "merged_at".');
        }

        $mergedAt = $data['merged_at'];
        $changeState = match (true) {
            'open' === $state => BranchChangeState::Open,
            'closed' === $state && is_string($mergedAt) => BranchChangeState::Merged,
            'closed' === $state && null === $mergedAt => BranchChangeState::ClosedUnmerged,
            default => throw new ForgeReadFailed(sprintf('GitHub listed a pull request in the state "%s" this forge cannot read.', $state)),
        };

        // Checked before the head is compared, because a malformed head would otherwise read as another commit and hide the branch's pull request.
        return ['state' => $changeState, 'head' => $this->commitOf($this->calls->text($data, 'head', 'sha'))->hash, 'labels' => $this->labelsOf($data)];
    }

    // Each part encoded, because a branch name holds slashes GitHub reads as part of the path; an empty or dot-led part is refused, because git never names a branch so and it could step out of the path.
    private function segments(string $branch): string
    {
        $parts = explode('/', $branch);
        if (array_any($parts, static fn (string $part): bool => '' === $part || str_starts_with($part, '.'))) {
            throw new ForgeReadFailed(sprintf('The branch name "%s" is not one git gives a branch.', $branch));
        }

        return implode('/', array_map(rawurlencode(...), $parts));
    }

    /**
     * @param array<mixed> $branch
     */
    private function headOf(array $branch): CommitId
    {
        return $this->commitOf($this->calls->text($branch, 'commit', 'sha'));
    }

    /**
     * @return list<string>
     */
    private function emailsOf(mixed $commit): array
    {
        $data = is_array($commit) ? $commit : throw new ForgeReadFailed('GitHub listed a commit that is not an object.');

        // Both are required, because a commit whose author or committer cannot be told may be a person's.
        return [$this->calls->text($data, 'commit', 'author', 'email'), $this->calls->text($data, 'commit', 'committer', 'email')];
    }

    // Read again right before the delete, because GitHub's ref deletion takes no expected head; the short window between this read and the delete is accepted, because cleanup candidates have been idle for weeks.
    private function refuseUnlessStillDeletable(ForgeBranch $branch): void
    {
        if ($this->defaultBranch() === $branch->name) {
            throw new ForgeReadFailed('it is the default branch now');
        }

        $current = $this->calls->object($this->repository->path().'/branches/'.$this->segments($branch->name));
        // GitHub answers a renamed branch by redirecting to its new name, which must not be deleted in its place.
        if ($this->calls->text($current, 'name') !== $branch->name) {
            throw new ForgeReadFailed('GitHub answered another branch');
        }

        if ($this->protectedOf($current)) {
            throw new ForgeReadFailed('it is protected now');
        }

        $head = $this->headOf($current);
        if ($head->hash !== $branch->head->hash) {
            throw new ForgeReadFailed(sprintf('it moved to %s since it was listed at %s', $head->hash, $branch->head->hash));
        }
    }

    private function commitOf(string $sha): CommitId
    {
        try {
            return new CommitId($sha);
        } catch (CommitIdMalformed $malformed) {
            throw new ForgeReadFailed('GitHub answered a commit that is not a commit id.', $malformed->getCode(), $malformed);
        }
    }

    /**
     * @param array<mixed> $branch
     */
    private function protectedOf(array $branch): bool
    {
        $protected = $branch['protected'] ?? null;

        return is_bool($protected) ? $protected : throw new ForgeReadFailed('GitHub answered a branch without "protected".');
    }

    /**
     * @param array<mixed> $pull
     *
     * @return list<string>
     */
    private function labelsOf(array $pull): array
    {
        $labels = $pull['labels'] ?? null;
        if (!is_array($labels) || !array_is_list($labels)) {
            throw new ForgeReadFailed('GitHub listed a pull request without the list "labels".');
        }

        return array_map(fn (mixed $label): string => $this->calls->text(is_array($label) ? $label : [], 'name'), $labels);
    }
}
