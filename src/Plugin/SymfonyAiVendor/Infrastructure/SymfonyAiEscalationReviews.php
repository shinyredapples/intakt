<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\TierInvoker;
use Intakt\Core\Review\Contract\EscalationRequest;
use Intakt\Core\Review\Contract\EscalationReviewer;
use Intakt\Core\Review\Contract\EscalationVerdict;
use Intakt\Plugin\Vendor\Contract\EscalationReviews;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Throwable;

#[AsAlias(EscalationReviews::class)]
final readonly class SymfonyAiEscalationReviews implements EscalationReviews
{
    public function __construct(
        private TierInvoker $tiers,
        private EscalationReviewer $reviewer,
        private UsageLedger $usage,
        private VendorAccountLimit $accounts,
    ) {
    }

    #[Override]
    public function escalate(string $project, EscalationRequest $request): ?EscalationVerdict
    {
        $answering = $this->tiers->answering($project, self::TIER);

        return $this->accounts->run($answering->provider, fn (): ?EscalationVerdict => $this->verdictOn($answering, $this->usage->reserve($project, new AnsweringTier(self::TIER, $answering), $request->round), $request));
    }

    private function verdictOn(ResolvedModel $answering, UsageReservation $reservation, EscalationRequest $request): ?EscalationVerdict
    {
        $platform = new TierRoutedPlatform($this->tiers, $answering);

        try {
            $verdict = $this->reviewer->review($request, $platform, $answering->model);
            // Boundary: leaves the call unsettled and rethrows it, because any failure of the call must reach the queue, which tries the escalation again.
        } catch (Throwable $failure) {
            $this->usage->leaveUnsettled($reservation, $failure, ...$platform->rounds());

            throw $failure;
        }

        $this->usage->settle($reservation, ...$platform->rounds());

        return $verdict;
    }
}
