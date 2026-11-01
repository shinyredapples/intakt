<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\ProjectNotConfigured;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierNotMapped;
use Intakt\Core\Ai\Contract\TierProviderDisabled;
use Intakt\Core\Ai\Contract\TierResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ConfiguredTierResolver implements TierResolver
{
    /**
     * @param array<string, array{tiers: array<string, array{provider: string, model: non-empty-string, substitutes?: list<array{provider: string, model: non-empty-string}>}>}> $projects
     * @param array<string, string>                                                                                                                                              $disabledProviders
     */
    public function __construct(
        #[Autowire('%intakt.projects%')]
        private array $projects,
        #[Autowire('%intakt.ai.disabled_providers%')]
        private array $disabledProviders,
    ) {
    }

    public function projects(): array
    {
        return array_map(strval(...), array_keys($this->projects));
    }

    public function resolve(string $project, Tier $tier): ResolvedModel
    {
        $mapping = $this->mapping($project, $tier);
        $provider = Provider::from($mapping['provider']);

        if (isset($this->disabledProviders[$provider->value])) {
            throw new TierProviderDisabled($project, $tier, $this->disabledProviders[$provider->value]);
        }

        return new ResolvedModel($provider, $mapping['model']);
    }

    public function substitutes(string $project, Tier $tier): array
    {
        $substitutes = [];

        foreach ($this->mapping($project, $tier)['substitutes'] ?? [] as $substitute) {
            $provider = Provider::from($substitute['provider']);

            if (!isset($this->disabledProviders[$provider->value])) {
                $substitutes[] = new ResolvedModel($provider, $substitute['model']);
            }
        }

        return $substitutes;
    }

    public function hasSubstitutes(string $project, Tier $tier): bool
    {
        return [] !== ($this->mapping($project, $tier)['substitutes'] ?? []);
    }

    /**
     * @return array{provider: string, model: non-empty-string, substitutes?: list<array{provider: string, model: non-empty-string}>}
     */
    private function mapping(string $project, Tier $tier): array
    {
        return ($this->projects[$project] ?? throw new ProjectNotConfigured($project))['tiers'][$tier->value] ?? throw new TierNotMapped($project, $tier);
    }
}
