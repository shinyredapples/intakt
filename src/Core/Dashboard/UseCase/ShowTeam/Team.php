<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowTeam;

final readonly class Team
{
    /**
     * @param list<TeamAccount> $accounts
     */
    public function __construct(
        public array $accounts,
    ) {
    }
}
