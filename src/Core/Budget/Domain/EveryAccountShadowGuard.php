<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Domain;

use Intakt\Core\Budget\Contract\BudgetServices;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\EnabledAccounts;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\ShadowStartGuard;
use Intakt\Core\Budget\Contract\StartDecision;
use Intakt\Core\Budget\Contract\StartGuard;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsAlias(ShadowStartGuard::class)]
final readonly class EveryAccountShadowGuard implements ShadowStartGuard
{
    public function __construct(
        #[Autowire(service: BudgetServices::SHADOW_CEILING_GUARD)]
        private StartGuard $ceilings,
        private EnabledAccounts $accounts,
    ) {
    }

    // A provider without an enabled account starts, because the vendor names that failure itself.
    #[Override]
    public function decide(Provider $provider): StartDecision
    {
        $decisions = array_map(fn (Account $account): StartDecision => $this->ceilings->decide($account, 0), $this->accounts->of($provider));

        return array_find($decisions, static fn (StartDecision $decision): bool => !$decision->admits()) ?? StartDecision::Start;
    }
}
