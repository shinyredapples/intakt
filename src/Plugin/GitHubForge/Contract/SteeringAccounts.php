<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

interface SteeringAccounts
{
    /**
     * Whether the account has write access on the project's repository, asked of the forge on every call.
     *
     * @throws TrustNotChecked
     */
    public function steers(string $project, string $login): bool;
}
