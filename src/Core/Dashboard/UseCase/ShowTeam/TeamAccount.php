<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowTeam;

use Intakt\Core\Ai\Contract\Account;

final readonly class TeamAccount
{
    /**
     * @param list<TeamRole> $roles
     */
    public function __construct(
        public Account $account,
        public AccountState $state,
        public array $roles = [],
    ) {
    }
}
