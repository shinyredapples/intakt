<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\EveryKeyRejected;
use Intakt\Core\Ai\Contract\EveryProviderOut;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierInvoker;
use Intakt\Core\Build\Contract\Sizer;
use Intakt\Core\Build\Contract\SizingRequest;
use Intakt\Core\Build\Contract\SizingVerdict;
use Intakt\Plugin\Vendor\Contract\SizingVendor;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Throwable;

#[AsAlias(SizingVendor::class)]
final readonly class SymfonyAiSizingVendor implements SizingVendor
{
    // The standard tier, because readiness and size need a read of the whole task, which is more than sorting findings.
    public const Tier SIZING_TIER = Tier::Standard;

    public function __construct(
        private TierInvoker $tiers,
        private Sizer $sizer,
        private UsageLedger $usage,
        private VendorAccountLimit $accounts,
    ) {
    }

    #[Override]
    public function size(string $project, SizingRequest $request): ?SizingVerdict
    {
        $answering = $this->answering($project);

        return $this->accounts->run($answering->provider, fn (): ?SizingVerdict => $this->verdictOn($answering, $this->usage->reserveForTask($project, new AnsweringTier(self::SIZING_TIER, $answering), $request->task), $request));
    }

    private function answering(string $project): ResolvedModel
    {
        try {
            return $this->tiers->answering($project, self::SIZING_TIER);
        } catch (EveryAccountCoolingDown|EveryProviderOut|EveryKeyRejected $out) {
            throw TypedProviderFailure::of($out);
        }
    }

    private function verdictOn(ResolvedModel $answering, UsageReservation $reservation, SizingRequest $request): ?SizingVerdict
    {
        $platform = new TierRoutedPlatform($this->tiers, $answering);

        try {
            $verdict = $this->sizer->size($request, $platform, $answering->model);
            // Boundary: leaves the call unsettled and rethrows it typed, because any provider failure must reach the callers as a vendor failure.
        } catch (Throwable $failure) {
            $this->usage->leaveUnsettled($reservation, $failure, ...$platform->rounds());

            throw TypedProviderFailure::of($failure);
        }

        $this->usage->settle($reservation, ...$platform->rounds());

        return $verdict;
    }
}
