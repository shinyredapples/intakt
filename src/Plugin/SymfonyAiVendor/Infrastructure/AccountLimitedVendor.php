<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Plugin\Vendor\Contract\ProviderFailedTransiently;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Plugin\Vendor\Contract\ProviderRejectedTheKey;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorAccountOut;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsAlias(Vendor::class)]
final readonly class AccountLimitedVendor implements Vendor
{
    public function __construct(
        #[Autowire(service: SymfonyAiVendor::class)]
        private Vendor $vendor,
        private TierResolver $resolver,
        private AccountCooldowns $cooldowns,
    ) {
    }

    public function review(string $project, Tier $tier, ReviewRequest $request): VendorReview
    {
        try {
            return $this->vendor->review($project, $tier, $request);
        } catch (ProviderRateLimited|ProviderFailedTransiently $out) {
            throw $this->nextAccountOrWait($project, $tier, $out);
        } catch (ProviderRejectedTheKey $rejected) {
            throw $this->nextAccountOrSubstituteOrFailure($project, $tier, $rejected);
        }
    }

    // The account that failed is cooling down by now, so the wait is until any account of the vendor is free, not until that account's own reset; a transient failure waits too, because its account cools down like a limited one. A tier with substitutes starts again at once instead, in a new conversation on whichever provider is free then, or ends blocked when none is.
    private function nextAccountOrWait(string $project, Tier $tier, ProviderRateLimited|ProviderFailedTransiently $out): VendorAccountOut|ProviderRateLimited
    {
        $wait = $this->cooldowns->secondsUntilAnAccountIsFree($this->resolver->resolve($project, $tier)->provider);
        if (0 === $wait || [] !== $this->resolver->substitutes($project, $tier)) {
            return new VendorAccountOut($out);
        }

        return new ProviderRateLimited($wait, $out);
    }

    // A rejected key ends the reviewer unless there is another account or a substitute to try, because it is the only failure that no wait overcomes.
    private function nextAccountOrSubstituteOrFailure(string $project, Tier $tier, ProviderRejectedTheKey $rejected): VendorAccountOut|ProviderRejectedTheKey
    {
        $free = 0 === $this->cooldowns->secondsUntilAnAccountIsFree($this->resolver->resolve($project, $tier)->provider);

        return $free || [] !== $this->resolver->substitutes($project, $tier) ? new VendorAccountOut($rejected) : $rejected;
    }
}
