<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\CheckHandBack;

use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\HandBack\Contract\AcceptedHandBack;
use Intakt\Core\HandBack\Contract\HandBack;
use Intakt\Core\HandBack\Contract\HandBackNotChecked;
use Intakt\Core\HandBack\Contract\HandBackRefusal;
use Intakt\Core\HandBack\Contract\RefusedHandBack;
use Intakt\Core\HandBack\Domain\BundleHeader;
use InvalidArgumentException;
use NoDiscard;

final readonly class CheckHandBack
{
    private const string BRANCHES = 'refs/heads/';

    public function __construct(
        private RepositoryGit $git,
        private BundleFile $bundles,
        private StrippedCommits $stripped,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     * @throws HandBackNotChecked
     * @throws RepositoryAttributesRefused
     */
    #[NoDiscard]
    public function check(HandBack $handBack): AcceptedHandBack|RefusedHandBack
    {
        $history = $this->givenHistory($handBack);
        $header = $this->bundles->headerOf($handBack->bundle);

        if (!$header instanceof BundleHeader) {
            return new RefusedHandBack(HandBackRefusal::Unreadable);
        }

        $refusal = $this->refusalOfRefs($header, $history);

        if ($refusal instanceof HandBackRefusal) {
            return new RefusedHandBack($refusal);
        }

        $head = $header->references[$history->branch];
        $refusal = $this->refusalOfBase($header, $history)
            ?? $this->refusalToUnbundle($handBack, $history)
            ?? $this->refusalOfFastForward($head, $history)
            ?? $this->refusalOfMerges($handBack, $head, $history);

        if ($refusal instanceof HandBackRefusal) {
            return new RefusedHandBack($refusal);
        }

        return $this->stripped->of($history, $head);
    }

    private function givenHistory(HandBack $handBack): GivenHistory
    {
        $repository = $this->resolved($handBack->repository);
        $main = $this->mainOf($repository);
        $branch = $this->branchRef($repository, $handBack->branch);

        if ($branch === $main) {
            throw new InvalidArgumentException(sprintf('the task\'s branch "%s" is main itself', $handBack->branch));
        }

        // Because a branch not pushed yet starts at main in the build clone, it is checked against main.
        $base = $this->git->answer($repository, ['rev-parse', '--verify', '--quiet', $branch.'^{commit}'])
            ?? $this->git->output($repository, ['rev-parse', '--verify', $main.'^{commit}']);

        return new GivenHistory($this->git, $repository, $main, $branch, $base);
    }

    private function resolved(string $repository): string
    {
        // Because git runs inside the repository, where a relative --git-dir would point below the repository itself.
        $path = realpath($repository);

        if (false === $path || !is_dir($path)) {
            throw HandBackNotChecked::in($repository, 'it is no folder');
        }

        return $path;
    }

    private function mainOf(string $repository): string
    {
        // Main is the branch the repository's HEAD names, because the mirror's HEAD follows the remote's default branch.
        $main = $this->git->answer($repository, ['symbolic-ref', '--quiet', 'HEAD']);

        if (null === $main || !str_starts_with($main, self::BRANCHES)) {
            throw HandBackNotChecked::in($repository, 'it is no repository whose HEAD names main');
        }

        return $main;
    }

    private function branchRef(string $repository, string $branch): string
    {
        // Git's own branch rule, and the answer must be the name itself, because git expands a name such as "@{-1}" to another branch.
        if ($this->git->answer($repository, ['check-ref-format', '--branch', $branch]) !== $branch) {
            throw new InvalidArgumentException(sprintf('"%s" is not a branch name git accepts', $branch));
        }

        return self::BRANCHES.$branch;
    }

    private function refusalOfRefs(BundleHeader $header, GivenHistory $history): ?HandBackRefusal
    {
        if (array_key_exists($history->main, $header->references)) {
            return HandBackRefusal::TouchesMain;
        }

        return array_keys($header->references) === [$history->branch] ? null : HandBackRefusal::NamesAnotherRef;
    }

    private function refusalOfBase(BundleHeader $header, GivenHistory $history): ?HandBackRefusal
    {
        // The build was given main and the task's branch, so a bundle may only build on what those reach.
        foreach ($header->prerequisites as $prerequisite) {
            if (!$history->holds($prerequisite)) {
                return HandBackRefusal::NotOnBranch;
            }
        }

        return null;
    }

    private function refusalToUnbundle(HandBack $handBack, GivenHistory $history): ?HandBackRefusal
    {
        // Unbundling writes the objects only, never a ref, so a refused bundle moves no branch.
        return $history->unbundle($handBack->bundle) ? null : HandBackRefusal::Unreadable;
    }

    private function refusalOfFastForward(string $head, GivenHistory $history): ?HandBackRefusal
    {
        return $history->isFastForwardTo($head) ? null : HandBackRefusal::NotFastForward;
    }

    private function refusalOfMerges(HandBack $handBack, string $head, GivenHistory $history): ?HandBackRefusal
    {
        $merges = $history->newMerges($head);

        if ([] === $merges) {
            return null;
        }

        if (!$handBack->mergeOfMainAsked) {
            return HandBackRefusal::HoldsMerge;
        }

        // The parents after the first only, because the first is the branch's side of a merge of main, as "git merge main" on the branch writes it.
        foreach ($merges as $parents) {
            foreach (array_slice($parents, 1) as $merged) {
                if (!$history->isOnMain($merged)) {
                    return HandBackRefusal::MergesAnotherBranch;
                }
            }
        }

        return null;
    }
}
