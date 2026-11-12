<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\SteeringAccounts;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;

final readonly class ProjectSteeringAccounts implements SteeringAccounts
{
    public function __construct(
        private WatchedRepositories $repositories,
        private SteeringTrustCheck $trust,
    ) {
    }

    public function steers(string $project, string $login): bool
    {
        try {
            $repository = $this->repositories->addressOf($project)->fullName();
        } catch (ForgeReadFailed) {
            throw TrustNotChecked::becauseTheRepositoryIsMalformed();
        }

        return $this->trust->check($repository, $login)->steers();
    }
}
