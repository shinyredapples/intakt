<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowTeam;

final readonly class Team
{
    /**
     * @param list<TeamAccount> $accounts
     * @param list<TeamRole>    $roles
     */
    public function __construct(
        public array $accounts,
        public array $roles,
    ) {
    }
}
