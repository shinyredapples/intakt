<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Plugin\Vendor\Contract\ProviderFailedTransiently;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorAccountBusy;
use Intakt\Plugin\Vendor\Contract\VendorAccountOut;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsAlias(Vendor::class)]
final readonly class AccountLimitedVendor implements Vendor
{
    public function __construct(
        #[Autowire(service: SymfonyAiVendor::class)]
        private Vendor $vendor,
        private TierResolver $resolver,
        private VendorAccountLimit $accounts,
        private AccountCooldowns $cooldowns,
    ) {
    }

    /**
     * @throws VendorAccountBusy
     */
    public function review(string $project, Tier $tier, ReviewRequest $request): Verdict
    {
        $provider = $this->resolver->resolve($project, $tier)->provider;

        // Around the whole call, so a call refused for a busy account reserves and counts no usage.
        return $this->accounts->run($provider, function () use ($project, $tier, $request, $provider): Verdict {
            try {
                return $this->vendor->review($project, $tier, $request);
            } catch (ProviderRateLimited|ProviderFailedTransiently $out) {
                throw $this->nextAccountOrWait($provider, $out);
            }
        });
    }

    // The account that failed is cooling down by now, so the wait is until any account of the vendor is free, not until that account's own reset; a transient failure waits too, because its account cools down like a limited one.
    private function nextAccountOrWait(Provider $provider, ProviderRateLimited|ProviderFailedTransiently $out): VendorAccountOut|ProviderRateLimited
    {
        $wait = $this->cooldowns->secondsUntilAnAccountIsFree($provider);
        if (0 === $wait) {
            return new VendorAccountOut($out);
        }

        return new ProviderRateLimited($wait, $out);
    }
}
