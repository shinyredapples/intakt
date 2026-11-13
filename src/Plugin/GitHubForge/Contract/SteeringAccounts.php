<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

interface SteeringAccounts
{
    // Asked of the forge on every call, because write access can be taken away between two events.
    /**
     * @throws TrustNotChecked
     */
    public function steers(string $project, string $login): bool;
}
