<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AiServices;
use Intakt\Core\Ai\Contract\ListedAccounts;
use Intakt\Core\Ai\Contract\Provider;
use Override;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ConfiguredListedAccounts implements ListedAccounts
{
    /**
     * @param array<string, list<string>> $accounts
     */
    public function __construct(
        #[Autowire(param: AiServices::LISTED_ACCOUNTS)]
        private array $accounts,
    ) {
    }

    #[Override]
    public function of(Provider $provider): array
    {
        return array_map(static fn (string $name): Account => new Account($provider, $name), $this->accounts[$provider->value] ?? []);
    }
}
