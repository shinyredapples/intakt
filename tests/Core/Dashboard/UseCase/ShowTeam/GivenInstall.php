<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ShowTeam;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;

final readonly class GivenInstall
{
    /**
     * @param array<string, list<string>>                                                                  $accounts
     * @param list<string>                                                                                 $withoutKey
     * @param list<string>                                                                                 $coolingDown
     * @param list<string>                                                                                 $rejected
     * @param array<string, array<string, Provider>>                                                       $tiers
     * @param array<string, array{tier: string, sensitive_paths: list<string>, escalate_on: list<string>}> $routing
     * @param array<string, array<non-empty-string, Provider>>                                             $reviewers
     */
    public function __construct(
        public array $accounts = [],
        public array $withoutKey = [],
        public array $coolingDown = [],
        public array $rejected = [],
        public array $tiers = [],
        public array $routing = [],
        public array $reviewers = [],
    ) {
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

    // Routed to the standard tier by default, as a project without a build section is.
    public function project(string $name, Provider $standard, ?Provider $strong = null): self
    {
        return clone ($this, [
            'tiers' => [...$this->tiers, $name => [Tier::Standard->value => $standard, Tier::Strong->value => $strong ?? $standard]],
            'routing' => [...$this->routing, $name => ['tier' => Tier::Standard->value, 'sensitive_paths' => [], 'escalate_on' => []]],
        ]);
    }

    public function sensitivePaths(string $project, string ...$globs): self
    {
        $routing = $this->routing;
        $routing[$project]['sensitive_paths'] = array_values($globs);

        return clone ($this, ['routing' => $routing]);
    }

    public function tier(string $project, Tier $tier, Provider $provider): self
    {
        $tiers = $this->tiers;
        $tiers[$project][$tier->value] = $provider;

        return clone ($this, ['tiers' => $tiers]);
    }

    public function unmapped(string $project, Tier ...$unmapped): self
    {
        $tiers = $this->tiers;
        $tiers[$project] = array_diff_key($tiers[$project] ?? [], array_flip(array_map(static fn (Tier $tier): string => $tier->value, $unmapped)));

        return clone ($this, ['tiers' => $tiers]);
    }

    /**
     * @param non-empty-string $role
     */
    public function reviewer(string $project, string $role, Provider $provider): self
    {
        return clone ($this, ['reviewers' => [...$this->reviewers, $project => [...($this->reviewers[$project] ?? []), $role => $provider]]]);
    }

    public function unrouted(string $project): self
    {
        return clone ($this, ['routing' => array_diff_key($this->routing, [$project => true])]);
    }
}
