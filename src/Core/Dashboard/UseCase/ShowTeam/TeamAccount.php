<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowTeam;

use Intakt\Core\Ai\Contract\Account;

final readonly class TeamAccount
{
    public function __construct(
        public Account $account,
        public AccountState $state,
    ) {
    }
}
