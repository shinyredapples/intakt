<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Closure;
use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\KeyRejected;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierInvoker;
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
use Throwable;

#[AsAlias(Vendor::class)]
final readonly class AccountLimitedVendor implements Vendor
{
    public function __construct(
        #[Autowire(service: SymfonyAiVendor::class)]
        private Vendor $vendor,
        private TierResolver $resolver,
        private TierInvoker $tiers,
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
        return $this->onTheNextAccount($project, self::JUDGE_TIER, fn (): Judgement => $this->vendor->judge($project, $request));
    }

    #[Override]
    public function mapsAModelTo(string $project, Tier $tier): bool
    {
        return $this->vendor->mapsAModelTo($project, $tier);
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

        $wait = $this->carriedWait($out) ?? $this->tiers->secondsUntilFree($this->resolver->resolve($project, $tier));

        return 0 === $wait ? new VendorAccountOut($out) : new ProviderRateLimited($wait, $out);
    }

    // The account failover read its wait from the account and the pinned provider windows in the same read that found no account free, so it is used as it is; a second read could see a window that ended meanwhile. A limit read from a stream carries no wait, so nextAccountOrWait() reads the model's own windows for it.
    private function carriedWait(Throwable $out): ?int
    {
        for ($cause = $out->getPrevious(); $cause instanceof Throwable; $cause = $cause->getPrevious()) {
            if ($cause instanceof EveryAccountCoolingDown) {
                return $cause->secondsUntilAnAccountIsFree;
            }
        }

        return null;
    }

    // A rejected key ends the reviewer unless there is another account or a substitute to try, because it is the only failure that no wait overcomes. Only KeyRejected, which the account failover throws while another account is free, proves that another account of the provider is free; a cooldown reading is not that proof, so the rejected key is never called again at once.
    private function nextAccountOrSubstituteOrFailure(string $project, Tier $tier, ProviderRejectedTheKey $rejected): VendorAccountOut|ProviderRejectedTheKey
    {
        if ($this->resolver->hasSubstitutes($project, $tier) || $rejected->getPrevious() instanceof KeyRejected) {
            return new VendorAccountOut($rejected);
        }

        return $rejected;
    }
}
