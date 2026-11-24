<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowTeam;

use Intakt\Core\Ai\Contract\Provider;

final readonly class TeamRole
{
    /**
     * @param non-empty-string $name
     * @param list<Provider>   $providers
     */
    public function __construct(
        public TeamRoleKind $kind,
        public string $name,
        public array $providers,
    ) {
    }

    public static function developer(Provider ...$providers): self
    {
        return new self(TeamRoleKind::Developer, TeamRoleKind::Developer->value, array_values($providers));
    }

    // The kind before a reviewer's name, because the owner may name a reviewer role "developer".
    public function label(): string
    {
        return TeamRoleKind::Developer === $this->kind ? $this->name : $this->kind->value.' '.$this->name;
    }

    public function servesOn(Provider $provider): bool
    {
        return in_array($provider, $this->providers, true);
    }
}
