<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Infrastructure;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Build\Contract\BuildFacts;
use Intakt\Core\Build\Contract\BuildRouting;
use Intakt\Core\Build\Contract\BuildRoutingNotConfigured;
use Intakt\Core\Build\Contract\BuildServices;
use Intakt\Core\Build\Contract\TierChoice;
use Intakt\Core\Build\Domain\ModelRouting;
use Intakt\Core\Build\Domain\RoutingRules;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ConfiguredBuildRouting implements BuildRouting
{
    /**
     * @param array<string, array{tier: string, sensitive_paths: list<string>}> $routing
     */
    public function __construct(
        #[Autowire(param: BuildServices::ROUTING)]
        private array $routing,
        private ModelRouting $modelRouting,
    ) {
    }

    public function tierFor(string $project, BuildFacts $facts): TierChoice
    {
        $configured = $this->routing[$project] ?? throw new BuildRoutingNotConfigured($project);

        return $this->modelRouting->choose(new RoutingRules(Tier::from($configured['tier']), $configured['sensitive_paths']), $facts);
    }
}
