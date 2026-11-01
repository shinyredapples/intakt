<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Closure;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\KeyRejected;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Plugin\Vendor\Contract\ProviderFailedTransiently;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Plugin\Vendor\Contract\ProviderRejectedTheKey;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorAccountOut;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use Override;
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

    #[Override]
    public function review(string $project, Tier $tier, ReviewRequest $request): VendorReview
    {
        return $this->onTheNextAccount($project, $tier, fn (): VendorReview => $this->vendor->review($project, $tier, $request));
    }

    #[Override]
    public function judge(string $project, JudgeRequest $request): Judgement
    {
        return $this->onTheNextAccount($project, Tier::Light, fn (): Judgement => $this->vendor->judge($project, $request));
    }

    /**
     * @template T of object
     *
     * @param Closure(): T $call
     *
     * @return T
     */
    private function onTheNextAccount(string $project, Tier $tier, Closure $call): object
    {
        try {
            return $call();
        } catch (ProviderRateLimited|ProviderFailedTransiently $out) {
            throw $this->nextAccountOrWait($project, $tier, $out);
        } catch (ProviderRejectedTheKey $rejected) {
            throw $this->nextAccountOrSubstituteOrFailure($project, $tier, $rejected);
        }
    }

    // The account that failed is cooling down by now, so the wait is until any account of the vendor is free, not until that account's own reset; a transient failure waits too, because its account cools down like a limited one. A tier with substitutes starts again at once instead, in a new conversation on whichever provider is free then, or ends blocked when none is.
    private function nextAccountOrWait(string $project, Tier $tier, ProviderRateLimited|ProviderFailedTransiently $out): VendorAccountOut|ProviderRateLimited
    {
        if ($this->resolver->hasSubstitutes($project, $tier)) {
            return new VendorAccountOut($out);
        }

        $wait = $this->cooldowns->secondsUntilAnAccountIsFree($this->resolver->resolve($project, $tier)->provider);

        return 0 === $wait ? new VendorAccountOut($out) : new ProviderRateLimited($wait, $out);
    }

    // A rejected key ends the reviewer unless there is another account or a substitute to try, because it is the only failure that no wait overcomes. Another account is known only from KeyRejected, which the account failover throws while one is free; a free cooldown says nothing here, because a provider without an accounts list reports zero and its one key would be called again forever.
    private function nextAccountOrSubstituteOrFailure(string $project, Tier $tier, ProviderRejectedTheKey $rejected): VendorAccountOut|ProviderRejectedTheKey
    {
        if ($this->resolver->hasSubstitutes($project, $tier) || $rejected->getPrevious() instanceof KeyRejected) {
            return new VendorAccountOut($rejected);
        }

        return $rejected;
    }
}
