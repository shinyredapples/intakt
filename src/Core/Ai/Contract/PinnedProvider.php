<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

// The router provider a model id pins after its colon (`deepinfra` in `moonshotai/Kimi-K3.1-Pro:deepinfra`), on the account the call runs on, because one account's limit at that provider leaves another account free.
final readonly class PinnedProvider
{
    public function __construct(
        public Account $account,
        public string $routerProvider,
    ) {
    }

    // A colon joins them because an account key (provider value and NAME_PATTERN) cannot hold one, so the first colon always ends the account.
    public function key(): string
    {
        return $this->account->key().':'.$this->routerProvider;
    }
}
