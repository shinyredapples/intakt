<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;

// A failure instead of a no, because an answer that could not be read must not read as a decision about the account.
interface SteeringAccounts
{
    /**
     * @throws TrustNotChecked
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     */
    public function steers(string $project, string $account): bool;
}
