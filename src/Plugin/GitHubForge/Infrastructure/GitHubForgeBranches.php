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
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;

// Every answer is checked field by field, because a branch read wrongly could be deleted; a doubt fails the read and cleanup deletes nothing.
final readonly class GitHubForgeBranches implements ForgeBranches
{
    private const int NO_CONTENT = 204;

    public function __construct(
        private GitHubBranchCalls $calls,
        private WatchedRepositories $repositories,
        private GitHubMoments $moments,
        private SteeringIdentities $identities,
    ) {
    }

    public function servesProject(string $project): bool
    {
        return null !== $this->repositories->nameOf($project);
    }

    public function branchesOf(string $project): array
    {
        $path = $this->repositories->addressOf($project)->path();
        $default = $this->calls->text($this->calls->object($path), 'default_branch');

        return array_map(fn (mixed $branch): ForgeBranch => $this->branchOf($branch, $default), $this->calls->pages($path.'/branches', []));
    }

    public function changeOf(string $project, ForgeBranch $branch): ?BranchChange
    {
        $address = $this->repositories->addressOf($project);
        $pulls = $this->calls->pages($address->path().'/pulls', ['state' => 'all', 'head' => $address->headOf($branch->name)]);
        $ofHead = array_values(array_filter(array_map($this->changeOfPull(...), $pulls), static fn (array $pull): bool => $pull[0] === $branch->head->hash));
        $changes = array_column($ofHead, 1);
        $open = array_filter($changes, static fn (BranchChange $change): bool => BranchChangeState::Open === $change->state);
        // GitHub lists the newest first, so the first closed one decides once none is open.
        $deciding = array_first($open) ?? array_first($changes);
        // The labels of every pull request of the head, because a `keep` on an older one still asks to keep the branch.
        $labels = array_values(array_unique(array_merge([], ...array_map(static fn (BranchChange $change): array => $change->labels, $changes))));

        return null === $deciding ? null : new BranchChange($deciding->state, $deciding->closedAt, $labels);
    }

    public function deleteBranch(string $project, ForgeBranch $branch): void
    {
        $path = $this->repositories->addressOf($project)->path();
        // GitHub's ref deletion takes no expected head, so the head is read right before it; the short window between the two is accepted because cleanup candidates have been idle for weeks.
        if ($this->headRightBeforeDeleting($path, $branch) !== $branch->head->hash) {
            throw new ForgeWriteFailed(sprintf('The branch "%s" moved since it was listed, so it was not deleted.', $branch->name));
        }

        $status = $this->calls->delete($path.'/git/refs/heads/'.$this->segments($branch->name));
        if (self::NO_CONTENT !== $status) {
            throw new ForgeWriteFailed(sprintf('GitHub answered %d to a branch deletion.', $status));
        }
    }

    public function hasCommitByPerson(string $project, ForgeBranch $branch): bool
    {
        $path = $this->repositories->addressOf($project)->path();
        $default = $this->calls->text($this->calls->object($path), 'default_branch');
        // Compared with the default branch's commit and not its name, because a default branch that advanced between two pages would shift commits onto a page already read.
        $base = $this->commitOf($this->calls->text($this->calls->object($path.'/branches/'.$this->segments($default)), 'commit', 'sha'));
        $commits = $this->calls->pages($path.'/compare/'.$base->hash.'...'.$branch->head->hash, [], 'commits');

        return array_any($commits, fn (mixed $commit): bool => !$this->isIntakts($commit));
    }

    private function headRightBeforeDeleting(string $path, ForgeBranch $branch): string
    {
        try {
            return $this->calls->text($this->calls->object($path.'/branches/'.$this->segments($branch->name)), 'commit', 'sha');
        } catch (ForgeReadFailed $unread) {
            throw new ForgeWriteFailed(sprintf('The head of the branch "%s" could not be read, so it was not deleted.', $branch->name), $unread->getCode(), $unread);
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

    // A commit without a GitHub account is a person's, because only this App's own bot account is Intakt.
    private function isIntakts(mixed $commit): bool
    {
        $author = is_array($commit) ? ($commit['author'] ?? null) : throw new ForgeReadFailed('GitHub listed a commit that is not an object.');
        $login = is_array($author) ? ($author['login'] ?? null) : null;
        if (!is_string($login) || '' === $login || !$this->identities->isBot($login)) {
            return false;
        }

        try {
            return $this->identities->isOwn($login);
        } catch (TrustNotChecked $unchecked) {
            throw new ForgeReadFailed('The author of a commit could not be checked against this App: '.$unchecked->getMessage(), $unchecked->getCode(), $unchecked);
        }
    }

    private function branchOf(mixed $branch, string $default): ForgeBranch
    {
        $data = is_array($branch) ? $branch : throw new ForgeReadFailed('GitHub listed a branch that is not an object.');
        $name = $this->calls->text($data, 'name');
        $protected = $data['protected'] ?? null;

        return new ForgeBranch($name, $this->commitOf($this->calls->text($data, 'commit', 'sha')), $name === $default, is_bool($protected) ? $protected : throw new ForgeReadFailed('GitHub listed a branch without "protected".'), ForgeBranch::isReleaseName($name));
    }

    /**
     * @return array{string, BranchChange}
     */
    private function changeOfPull(mixed $pull): array
    {
        $data = is_array($pull) ? $pull : throw new ForgeReadFailed('GitHub listed a pull request that is not an object.');
        $state = $this->calls->text($data, 'state');
        $mergedAt = $data['merged_at'] ?? null;
        $closedAt = $data['closed_at'] ?? null;

        $change = match (true) {
            'open' === $state => new BranchChange(BranchChangeState::Open, null, $this->labelsOf($data)),
            'closed' === $state && is_string($mergedAt) => new BranchChange(BranchChangeState::Merged, $this->moments->parse($mergedAt), $this->labelsOf($data)),
            'closed' === $state && is_string($closedAt) => new BranchChange(BranchChangeState::ClosedUnmerged, $this->moments->parse($closedAt), $this->labelsOf($data)),
            default => throw new ForgeReadFailed(sprintf('GitHub listed a pull request in the state "%s" without the moment it closed.', $state)),
        };

        // Checked before the head is compared, because a malformed head would otherwise read as another commit and hide an open pull request.
        return [$this->commitOf($this->calls->text($data, 'head', 'sha'))->hash, $change];
    }

    /**
     * @param array<mixed> $pull
     *
     * @return list<string>
     */
    private function labelsOf(array $pull): array
    {
        return array_map(fn (mixed $label): string => $this->calls->text(is_array($label) ? $label : [], 'name'), $this->calls->list($pull, 'labels'));
    }

    // Each part encoded, because a branch name holds slashes GitHub reads as part of the path.
    private function segments(string $branch): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $branch)));
    }
}
