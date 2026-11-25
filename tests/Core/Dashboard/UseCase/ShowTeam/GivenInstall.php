<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ShowTeam;

use Intakt\Core\Ai\Contract\Provider;

final readonly class GivenInstall
{
    /**
     * @param array<string, list<string>>                               $accounts
     * @param list<string>                                              $withoutKey
     * @param list<string>                                              $coolingDown
     * @param list<string>                                              $rejected
     * @param array<string, array{default: Provider, strong: Provider}> $developers
     * @param array<string, array<string, Provider>>                    $reviewers
     * @param list<string>                                              $unrouted
     * @param list<string>                                              $unresolvable
     */
    public function __construct(
        public array $accounts = [],
        public array $withoutKey = [],
        public array $coolingDown = [],
        public array $rejected = [],
        public array $developers = [],
        public array $reviewers = [],
        public array $unrouted = [],
        public array $unresolvable = [],
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

    public function project(string $name, Provider $default, ?Provider $strong = null): self
    {
        return clone ($this, ['developers' => [...$this->developers, $name => ['default' => $default, 'strong' => $strong ?? $default]]]);
    }

    public function reviewer(string $project, string $role, Provider $provider): self
    {
        return clone ($this, ['reviewers' => [...$this->reviewers, $project => [...($this->reviewers[$project] ?? []), $role => $provider]]]);
    }

    public function unrouted(string $project): self
    {
        return clone ($this, ['unrouted' => [...$this->unrouted, $project]]);
    }

    public function unresolvable(string $project): self
    {
        return clone ($this, ['unresolvable' => [...$this->unresolvable, $project]]);
    }
}
