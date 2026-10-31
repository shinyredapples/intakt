<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorAccountBusy;
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
    ) {
    }

    /**
     * @throws VendorAccountBusy
     */
    public function review(string $project, Tier $tier, ReviewRequest $request): Verdict
    {
        // Around the whole call, so a call refused for a busy account reserves and counts no usage.
        return $this->accounts->run($this->resolver->resolve($project, $tier)->provider, fn (): Verdict => $this->vendor->review($project, $tier, $request));
    }
}
