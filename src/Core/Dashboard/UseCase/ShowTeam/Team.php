<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowTeam;

use Intakt\Core\Budget\Contract\AccountPick;

final readonly class Team
{
    /**
     * @param list<TeamAccount> $accounts
     * @param list<AccountPick> $picks
     */
    public function __construct(
        public array $accounts,
        public array $picks,
    ) {
    }
}
