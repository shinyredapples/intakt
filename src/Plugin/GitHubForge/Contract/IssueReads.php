<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;

interface IssueReads
{
    // Every page or a failure, because a listing that comes back short would sort the wrong task first and close waits that are still open.
    /**
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     */
    public function listOpen(string $project, ?string $validator): IssueListing;
}
