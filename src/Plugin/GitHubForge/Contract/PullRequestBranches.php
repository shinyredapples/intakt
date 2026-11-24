<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;

interface PullRequestBranches
{
    // Every open pull request or a failure, because one left out would let its task be built afresh beside it.
    /**
     * @return list<BranchPullRequest>
     *
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     */
    public function openOf(string $project): array;
}
