<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use Intakt\Plugin\GitHubForge\Contract\SteeringAccounts;

// Write access as a fixed list of logins, because the sync only needs a yes or no per account.
final readonly class GrantedAccounts implements SteeringAccounts
{
    /**
     * @param list<string> $writers
     */
    public function __construct(
        private array $writers = ['ana'],
    ) {
    }

    public function steers(string $project, string $account): bool
    {
        return in_array($account, $this->writers, true);
    }
}
