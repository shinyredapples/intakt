<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ShowTeam;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;

final readonly class GivenInstall
{
    /**
     * @param array<string, array{tier: string, sensitive_paths: list<string>, escalate_on: list<string>}> $routing
     * @param array<string, Provider>                                                                      $tiers
     * @param array<string, list<string>>                                                                  $accounts
     * @param list<string>                                                                                 $coolingDown
     * @param list<string>                                                                                 $rejected
     * @param list<string>                                                                                 $projects
     */
    public function __construct(
        public array $routing = [],
        public array $tiers = [],
        public array $accounts = [],
        public array $coolingDown = [],
        public array $rejected = [],
        public array $projects = [],
    ) {
    }

    /**
     * @param list<string> $sensitivePaths
     */
    public function routing(string $project, Tier $defaultTier, array $sensitivePaths = []): self
    {
        return clone ($this, [
            'routing' => [...$this->routing, $project => ['tier' => $defaultTier->value, 'sensitive_paths' => $sensitivePaths, 'escalate_on' => []]],
            'projects' => [...$this->projects, $project],
        ]);
    }

    public function unroutedProject(string $project): self
    {
        return clone ($this, ['projects' => [...$this->projects, $project]]);
    }

    public function tier(Tier $tier, Provider $provider): self
    {
        return clone ($this, ['tiers' => [...$this->tiers, $tier->value => $provider]]);
    }

    public function accounts(Provider $provider, string ...$names): self
    {
        return clone ($this, ['accounts' => [...$this->accounts, $provider->value => array_values($names)]]);
    }

    public function coolingDown(string $key): self
    {
        return clone ($this, ['coolingDown' => [...$this->coolingDown, $key]]);
    }

    public function rejected(string $key): self
    {
        return clone ($this, ['rejected' => [...$this->rejected, $key]]);
    }
}
