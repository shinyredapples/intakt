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

        return array_map(
            static fn (string|int $name, array $names): self => new self((string) $name, $names),
            array_keys($byRole),
            $byRole,
        );
    }
}
