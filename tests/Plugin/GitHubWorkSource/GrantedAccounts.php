<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use Intakt\Plugin\GitHubForge\Contract\SteeringAccounts;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;

final class GrantedAccounts implements SteeringAccounts
{
    public int $checks = 0;
    public bool $unreachable = false;

    /**
     * @param list<string> $writers
     */
    public function __construct(
        public array $writers = ['ana', 'bo'],
    ) {
    }

    public function steers(string $project, string $login): bool
    {
        ++$this->checks;

        return $this->unreachable ? throw TrustNotChecked::becauseGitHubWasUnreachable('TransportException') : in_array($login, $this->writers, true);
    }
}
