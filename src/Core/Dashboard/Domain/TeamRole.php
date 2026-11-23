<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

final readonly class TeamRole
{
    /**
     * @param list<string> $accounts
     */
    public function __construct(
        public string $name,
        public array $accounts,
    ) {
    }

    /**
     * @param list<TeamAccount> $accounts
     *
     * @return list<self>
     */
    public static function servedBy(array $accounts): array
    {
        $byRole = [];

        foreach ($accounts as $account) {
            foreach ($account->roles as $role) {
                $byRole[$role][] = $account->name();
            }
        }

        $roles = [];

        foreach ($byRole as $name => $names) {
            $roles[] = new self((string) $name, $names);
        }

        return $roles;
    }
}
