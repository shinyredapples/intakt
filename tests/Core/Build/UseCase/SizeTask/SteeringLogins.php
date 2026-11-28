<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\SizeTask;

use Intakt\Plugin\GitHubForge\Contract\SteeringAccounts;
use Override;

final readonly class SteeringLogins implements SteeringAccounts
{
    /**
     * @var list<string>
     */
    private array $logins;

    public function __construct(string ...$logins)
    {
        $this->logins = array_values($logins);
    }

    #[Override]
    public function steers(string $project, string $account): bool
    {
        return in_array($account, $this->logins, true);
    }
}
