<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowTeam;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\TierResolutionFailure;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Build\Contract\BuildFacts;
use Intakt\Core\Build\Contract\BuildRouting;
use Intakt\Core\Build\Contract\BuildRoutingNotConfigured;
use Intakt\Core\Build\Contract\Setback;
use Intakt\Core\Review\Contract\ReviewerRoles;

// Read from the configuration, never from the enabled accounts, so a role whose accounts are all disabled still shows.
final readonly class TeamRoster
{
    private const string DEVELOPER = 'Developer';

    public function __construct(
        private ReviewerRoles $reviewers,
        private TierResolver $tiers,
        private BuildRouting $builds,
    ) {
    }

    /**
     * @return array<string, list<Provider>>
     */
    public function providersByRole(): array
    {
        $roles = [self::DEVELOPER => $this->developerProviders()];

        foreach (Provider::cases() as $provider) {
            foreach ($this->reviewers->servedBy($provider) as $role) {
                $roles[$role][] = $provider;
            }
        }

        return array_filter($roles, static fn (array $providers): bool => [] !== $providers);
    }

    // The tier each project builds on and the one a setback escalates it to, because both are configured routing; a tier an issue label picks is not.
    /**
     * @return list<Provider>
     */
    private function developerProviders(): array
    {
        $providers = [];

        foreach ($this->tiers->projects() as $project) {
            foreach ($this->buildFacts() as $facts) {
                $provider = $this->providerOfBuild($project, $facts);
                if ($provider instanceof Provider) {
                    $providers[$provider->value] = $provider;
                }
            }
        }

        return array_values($providers);
    }

    /**
     * @return list<BuildFacts>
     */
    private function buildFacts(): array
    {
        return [new BuildFacts(null, []), ...array_map(static fn (Setback $setback): BuildFacts => new BuildFacts(null, [], $setback), Setback::cases())];
    }

    // Left out, because a project whose routing or tier is not configured has no Developer to show.
    private function providerOfBuild(string $project, BuildFacts $facts): ?Provider
    {
        try {
            return $this->tiers->configured($project, $this->builds->tierFor($project, $facts)->tier)->provider;
        } catch (BuildRoutingNotConfigured|TierResolutionFailure) {
            return null;
        }
    }
}
