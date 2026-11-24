<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ShowTeam;

use Intakt\Core\Ai\Contract\Provider;

final readonly class GivenInstall
{
    /**
     * @param array<string, list<string>> $accounts
     * @param list<string>                $withoutKey
     * @param list<string>                $coolingDown
     * @param list<string>                $rejected
     */
    public function __construct(
        public array $accounts = [],
        public array $withoutKey = [],
        public array $coolingDown = [],
        public array $rejected = [],
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
}
