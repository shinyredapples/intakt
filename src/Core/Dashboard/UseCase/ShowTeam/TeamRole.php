<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowTeam;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Build\Contract\BuildRuntime;

final readonly class TeamRole
{
    /**
     * @param non-empty-string $name
     * @param list<Provider>   $providers
     * @param list<Provider>   $notRunnable the providers the role is routed to that its runtime cannot use
     */
    public function __construct(
        public TeamRoleKind $kind,
        public string $name,
        public array $providers,
        public array $notRunnable = [],
    ) {
    }

    // Every routed provider stays listed, so the owner sees a tier routed where the build runtime cannot follow, instead of it silently missing.
    /**
     * @param list<Provider> $providers
     */
    public static function developer(array $providers): self
    {
        return new self(TeamRoleKind::Developer, TeamRoleKind::Developer->value, $providers, array_values(array_filter($providers, static fn (Provider $provider): bool => BuildRuntime::PROVIDER !== $provider)));
    }

    // The kind before a reviewer's name, because the owner may name a reviewer role "developer".
    public function label(): string
    {
        return TeamRoleKind::Developer === $this->kind ? $this->name : $this->kind->value.' '.$this->name;
    }

    // An account of a provider the runtime cannot use never takes the role's work, so it does not serve it.
    public function servesOn(Provider $provider): bool
    {
        return in_array($provider, $this->providers, true) && !in_array($provider, $this->notRunnable, true);
    }
}
