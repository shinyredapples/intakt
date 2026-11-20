<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;

// A failure instead of an empty history, because a history that could not be read must not read as one nobody steered.
interface IssueHistories
{
    /**
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     */
    public function of(string $project, int $number): IssueHistory;
}
