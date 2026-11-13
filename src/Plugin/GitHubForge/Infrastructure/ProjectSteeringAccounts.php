<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\GitHubForge\Contract\SteeringAccounts;

// Through the trust check, so the intake counts write access by the same rule as every other event: triage and read never steer.
final readonly class ProjectSteeringAccounts implements SteeringAccounts
{
    public function __construct(
        private WatchedRepositories $repositories,
        private SteeringTrustCheck $trust,
    ) {
    }

    public function steers(string $project, string $account): bool
    {
        $address = $this->repositories->addressOf($project);

        return $this->trust->check($address->owner().'/'.$address->name(), $account)->steers();
    }
}
