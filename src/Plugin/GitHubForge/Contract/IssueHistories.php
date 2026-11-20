<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;

// A contract of its own, because the work source vouches for an item from its history and may use only this module's contract.
interface IssueHistories
{
    /**
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     */
    public function of(string $project, int $number): IssueHistory;
}
