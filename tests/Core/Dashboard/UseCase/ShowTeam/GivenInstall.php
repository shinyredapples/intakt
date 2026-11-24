<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ShowTeam;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;

final readonly class GivenInstall
{
    /**
     * @param array<string, array{tier: string, sensitive_paths: list<string>, escalate_on: list<string>}>                                                                                                                                                                                                                                                   $routing
     * @param array<string, Provider>                                                                                                                                                                                                                                                                                                                        $tiers
     * @param array<string, list<string>>                                                                                                                                                                                                                                                                                                                    $accounts
     * @param list<string>                                                                                                                                                                                                                                                                                                                                   $withoutKey
     * @param list<string>                                                                                                                                                                                                                                                                                                                                   $coolingDown
     * @param list<string>                                                                                                                                                                                                                                                                                                                                   $rejected
     * @param list<string>                                                                                                                                                                                                                                                                                                                                   $projects
     * @param array<string, array{quorum: string, reviewers: list<array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int}>, rules?: list<array{paths: list<string>, reviewers: list<array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int}>}>}> $panels
     */
    public function __construct(
        public array $routing = [],
        public array $tiers = [],
        public array $accounts = [],
        public array $withoutKey = [],
        public array $coolingDown = [],
        public array $rejected = [],
        public array $projects = [],
        public array $panels = [],
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

    public function withoutKey(string $key): self
    {
        return clone ($this, ['withoutKey' => [...$this->withoutKey, $key]]);
    }

    public function coolingDown(string $key): self
    {
        return clone ($this, ['coolingDown' => [...$this->coolingDown, $key]]);
    }

    public function rejected(string $key): self
    {
        return clone ($this, ['rejected' => [...$this->rejected, $key]]);
    }

    /**
     * @param non-empty-string $role
     */
    public function reviewer(string $project, string $role, Provider $provider): self
    {
        $panel = $this->panels[$project] ?? ['quorum' => 'all', 'reviewers' => []];
        $panel['reviewers'][] = $this->reviewerEntry($role, $provider);

        return clone ($this, ['panels' => [...$this->panels, $project => $panel]]);
    }

    /**
     * @param non-empty-string $role
     */
    public function ruleReviewer(string $project, string $role, Provider $provider): self
    {
        $panel = $this->panels[$project] ?? ['quorum' => 'all', 'reviewers' => []];
        $panel['rules'] = [...$panel['rules'] ?? [], ['paths' => ['src/Security/**'], 'reviewers' => [$this->reviewerEntry($role, $provider)]]];

        return clone ($this, ['panels' => [...$this->panels, $project => $panel]]);
    }

    /**
     * @param non-empty-string $role
     *
     * @return array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int}
     */
    private function reviewerEntry(string $role, Provider $provider): array
    {
        return ['role' => $role, 'tier' => Tier::Strong->value, 'provider' => $provider->value, 'prompt' => 'Review the change.', 'budget' => 1];
    }
}
