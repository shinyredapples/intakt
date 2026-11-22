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

    // Null for an issue that is closed, missing or a pull request, because none of them is a task; any other failure throws, so a failed read never passes for a missing issue.
    /**
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     */
    public function readOpen(string $project, int $number): ?ListedIssue;
}
