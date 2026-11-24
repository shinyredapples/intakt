<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Intakt\Core\Ai\Contract\Provider;

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

    // Every configured role, also one whose providers have no enabled account, because the owner must see a role nobody can serve.
    /**
     * @param array<string, list<Provider>> $providersByRole
     * @param list<TeamAccount>             $accounts
     *
     * @return list<self>
     */
    public static function of(array $providersByRole, array $accounts): array
    {
        $roles = [];

        foreach ($providersByRole as $name => $providers) {
            $serving = array_filter($accounts, static fn (TeamAccount $account): bool => in_array($account->account->provider, $providers, true));
            $roles[] = new self($name, array_values(array_map(static fn (TeamAccount $account): string => $account->label(), $serving)));
        }

        return $roles;
    }
}
