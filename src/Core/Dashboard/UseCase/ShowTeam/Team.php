<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowTeam;

use Intakt\Core\Ai\Contract\Account;

final readonly class Team
{
    /**
     * @param list<TeamAccount> $accounts
     */
    public function __construct(
        public array $accounts,
    ) {
    }

    // Keyed by the role's name, so a role that runs on several accounts shows once with all of them.
    /**
     * @return array<string, list<array{account: Account, role: TeamRole}>>
     */
    public function byRole(): array
    {
        $holders = [];

        foreach ($this->accounts as $row) {
            foreach ($row->roles as $role) {
                $holders[$role->name][] = ['account' => $row->account, 'role' => $role];
            }
        }

        return $holders;
    }
}
