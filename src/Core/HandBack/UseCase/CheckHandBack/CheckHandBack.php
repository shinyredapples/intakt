<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\CheckHandBack;

use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\HandBack\Contract\AcceptedHandBack;
use Intakt\Core\HandBack\Contract\HandBack;
use Intakt\Core\HandBack\Contract\HandBackBranchRefused;
use Intakt\Core\HandBack\Contract\HandBackChecks;
use Intakt\Core\HandBack\Contract\HandBackNotChecked;
use Intakt\Core\HandBack\Contract\HandBackRefusal;
use Intakt\Core\HandBack\Contract\RefusedHandBack;
use Intakt\Core\HandBack\Domain\BundleHeader;
use NoDiscard;
use Override;

final readonly class CheckHandBack implements HandBackChecks
{
    private const string BRANCHES = 'refs/heads/';

    public function __construct(
        private RepositoryGit $git,
        private BundleFile $bundles,
        private StrippedCommits $stripped,
        private IncomingObjects $incoming,
        private BundleSecretScan $secrets,
    ) {
    }

    /**
     * @throws HandBackBranchRefused
     * @throws HandBackNotChecked
     * @throws RepositoryAttributesRefused
     */
    #[Override]
    #[NoDiscard]
    public function check(HandBack $handBack): AcceptedHandBack|RefusedHandBack
    {
        $history = $this->givenHistory($handBack);
        $header = $this->bundles->headerOf($handBack->bundle);

        if (!$header instanceof BundleHeader) {
            return new RefusedHandBack(HandBackRefusal::Unreadable);
        }

        $refusal = $this->refusalOfRefs($header, $history) ?? $this->refusalOfBase($header, $history);

        if ($refusal instanceof HandBackRefusal) {
            return new RefusedHandBack($refusal);
        }

        $quarantine = $this->incoming->open($history->repository);

        try {
            $result = $this->checkedIn($history->withObjectsIn($quarantine), $handBack, $header->references[$history->branch]);

            if ($result instanceof AcceptedHandBack) {
                $this->incoming->keep($quarantine);
            }

            return $result;
        } finally {
            $this->incoming->drop($quarantine);
        }
    }

    private function givenHistory(HandBack $handBack): GivenHistory
    {
        $repository = $this->resolved($handBack->repository);
        $main = $this->mainOf($repository);
        $branch = $this->branchRef($repository, $handBack->branch);

        if ($branch === $main) {
            throw HandBackBranchRefused::isMain($handBack->branch);
        }

        $pushed = $this->git->answer($repository, ['rev-parse', '--verify', '--quiet', $branch.'^{commit}']);

        return new GivenHistory($this->git, $repository, $main, $branch, $pushed);
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
            throw HandBackBranchRefused::notAccepted($branch);
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

    /**
     * @throws HandBackNotChecked
     */
    private function checkedIn(GivenHistory $history, HandBack $handBack, string $head): AcceptedHandBack|RefusedHandBack
    {
        $refusal = $this->refusalToUnbundle($handBack, $history)
            ?? $this->refusalOfUnshipped($handBack, $head, $history)
            ?? $this->refusalOfFastForward($head, $history)
            ?? $this->refusalOfMerges($handBack, $head, $history);

        if ($refusal instanceof HandBackRefusal) {
            return new RefusedHandBack($refusal);
        }

        $stripped = $this->stripped->of($history, $head, $handBack->mountedCredentials);

        return $stripped instanceof AcceptedHandBack ? $this->scanned($stripped, $handBack, $history) : $stripped;
    }

    private function refusalToUnbundle(HandBack $handBack, GivenHistory $history): ?HandBackRefusal
    {
        // Unbundling writes the objects only, never a ref, so a refused bundle moves no branch.
        return $history->unbundle($handBack->bundle) ? null : HandBackRefusal::Unreadable;
    }

    /**
     * @throws HandBackNotChecked
     */
    private function refusalOfUnshipped(HandBack $handBack, string $head, GivenHistory $history): ?HandBackRefusal
    {
        $count = $this->bundles->packedObjectCount($handBack->bundle);

        if (null === $count) {
            return HandBackRefusal::Unreadable;
        }

        // Only what the bundle's own pack holds counts, because the header's prerequisites are the bundle's word, and the repository also holds other branches the build was never given.
        $shipped = $history->shippedObjects($count);
        $commits = $history->reachedCommits($head);

        if (null !== $commits && [] !== array_diff_key(array_flip($commits), $shipped)) {
            return HandBackRefusal::NotOnBranch;
        }

        $objects = null === $commits ? null : $history->reachedObjects($head);

        return null === $objects || [] !== array_diff_key(array_flip($objects), $shipped) ? HandBackRefusal::Incomplete : null;
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

    /**
     * @throws HandBackNotChecked
     */
    private function scanned(AcceptedHandBack $stripped, HandBack $handBack, GivenHistory $history): AcceptedHandBack|RefusedHandBack
    {
        // The stripped and redacted commits, because they are what the push sends.
        $refusal = $this->secrets->refusalOf($history, $stripped->head, $handBack->mountedCredentials);

        return $refusal instanceof HandBackRefusal ? new RefusedHandBack($refusal) : $stripped;
    }
}
