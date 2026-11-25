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

    /**
     * @return array<string, list<array{label: string, role: TeamRole}>>
     */
    // Keyed by the role's name, so a role that runs on several accounts shows once with all of them.
    public function byRole(): array
    {
        $holders = [];

        foreach ($this->accounts as $row) {
            foreach ($row->roles as $role) {
                $holders[$role->name][] = ['account' => $row->account, 'role' => $role];
            }
        }

        return array_map(
            static function (array $ofRole): array {
                $names = array_count_values(array_map(static fn (array $holder): string => $holder['account']->name, $ofRole));

                return array_map(
                    static fn (array $holder): array => ['label' => self::label($holder['account'], $names[$holder['account']->name] ?? 1), 'role' => $holder['role']],
                    $ofRole,
                );
            },
            $holders,
        );
    }

    private static function label(Account $account, int $holdersWithItsName): string
    {
        return 1 < $holdersWithItsName ? sprintf('%s (%s)', $account->name, $account->provider->value) : $account->name;
    }
}
