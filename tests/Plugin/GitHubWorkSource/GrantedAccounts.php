<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use Closure;
use Intakt\Plugin\GitHubForge\Contract\SteeringAccounts;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;

final class GrantedAccounts implements SteeringAccounts
{
    public int $checks = 0;
    public bool $unreachable = false;
    // Run once on the next check, so a test can force what another worker does in the middle of a tick.
    public ?Closure $during = null;

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
        $during = $this->during;
        $this->during = null;
        if (null !== $during) {
            $during();
        }

        return $this->unreachable ? throw TrustNotChecked::becauseGitHubWasUnreachable('TransportException') : in_array($login, $this->writers, true);
    }
}
