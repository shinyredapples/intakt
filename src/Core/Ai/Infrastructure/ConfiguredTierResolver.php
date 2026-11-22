<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\ProjectNames;
use Intakt\Core\Ai\Contract\AiServices;
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
     * @param array<string, array{tiers: array<string, array{provider: string, model: non-empty-string, max_output?: positive-int, substitutes?: list<array{provider: string, model: non-empty-string}>}>}> $projects
     * @param array<string, string>                                                                                                                                                                         $disabledProviders
     */
    public function __construct(
        #[Autowire(param: AiServices::PROJECTS)]
        private array $projects,
        #[Autowire(param: AiServices::DISABLED_PROVIDERS)]
        private array $disabledProviders,
    ) {
    }

    public function projects(): ProjectNames
    {
        return new ProjectNames(...array_map(strval(...), array_keys($this->projects)));
    }

    public function resolve(string $project, Tier $tier): ResolvedModel
    {
        $own = $this->configured($project, $tier);

        if ($this->isDisabled($own->provider)) {
            throw new TierProviderDisabled($project, $tier, $this->disabledProviders[$own->provider->value]);
        }

        return $own;
    }

    public function configured(string $project, Tier $tier): ResolvedModel
    {
        $mapping = $this->mapping($project, $tier);

        return new ResolvedModel(Provider::from($mapping['provider']), $mapping['model'], $mapping['max_output'] ?? null);
    }

    public function isDisabled(Provider $provider): bool
    {
        return isset($this->disabledProviders[$provider->value]);
    }

    public function substitutes(string $project, Tier $tier): array
    {
        $substitutes = [];

        foreach ($this->mapping($project, $tier)['substitutes'] ?? [] as $substitute) {
            $provider = Provider::from($substitute['provider']);

            if (!$this->isDisabled($provider)) {
                $substitutes[] = new ResolvedModel($provider, $substitute['model'], $this->mapping($project, $tier)['max_output'] ?? null);
            }
        }

        return $substitutes;
    }

    public function hasSubstitutes(string $project, Tier $tier): bool
    {
        return [] !== ($this->mapping($project, $tier)['substitutes'] ?? []);
    }

    /**
     * @return array{provider: string, model: non-empty-string, max_output?: positive-int, substitutes?: list<array{provider: string, model: non-empty-string}>}
     */
    private function mapping(string $project, Tier $tier): array
    {
        return ($this->projects[$project] ?? throw new ProjectNotConfigured($project))['tiers'][$tier->value] ?? throw new TierNotMapped($project, $tier);
    }
}
