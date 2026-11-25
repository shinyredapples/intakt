<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Infrastructure\CheckedInstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\SteeringIdentities;
use Intakt\Plugin\GitHubForge\Infrastructure\SteeringTrustCheck;

final readonly class TrustWiring
{
    public function __construct(
        public SteeringTrustCheck $check,
        public SteeringIdentities $identities,
        public GitHubCalls $calls,
        public CheckedInstallationTokens $tokens,
    ) {
    }
}
