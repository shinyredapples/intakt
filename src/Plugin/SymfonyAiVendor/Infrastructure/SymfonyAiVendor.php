<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierInvoker;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Reviewer;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Plugin\Vendor\Contract\ProviderFailedTransiently;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\RetryableExceptionInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Throwable;

// Public because no service injects it before the panel (plan T8), and an unused private service is removed.
#[Autoconfigure(public: true)]
final readonly class SymfonyAiVendor implements Vendor
{
    public function __construct(
        private TierInvoker $tiers,
        private TierResolver $resolver,
        private Reviewer $reviewer,
        private UsageLedger $usage,
        private VendorAccountLimit $accounts,
    ) {
    }

    public function review(string $project, Tier $tier, ReviewRequest $request): Verdict
    {
        // Before the usage is reserved, so a call refused for a busy account reserves and counts nothing.
        return $this->accounts->run($this->resolver->resolve($project, $tier)->provider, fn (): Verdict => $this->call($project, $tier, $request));
    }

    private function call(string $project, Tier $tier, ReviewRequest $request): Verdict
    {
        $platform = new TierRoutedPlatform($this->tiers, $project);
        $reservation = $this->usage->reserve($project, $tier);

        try {
            $verdict = $this->reviewer->review($request, $platform, $tier->value);
        } catch (IncompleteStreamException $incomplete) {
            // Left unsettled, never settled as well, so the call's usage is counted once.
            $this->usage->leaveUnsettled($reservation, $incomplete, ...$platform->rounds());

            return Verdict::incompleteStream($incomplete->getMessage());
        } catch (Throwable $failure) {
            $this->usage->leaveUnsettled($reservation, $failure, ...$platform->rounds());

            throw $this->typed($failure);
        }

        $this->usage->settle($reservation, ...$platform->rounds());

        return $this->readOrRefused($project, $tier, $verdict);
    }

    // Decided here in plain code, because a reviewer that never saw the change can still answer approve.
    private function readOrRefused(string $project, Tier $tier, Verdict $verdict): Verdict
    {
        if (Decision::Approve !== $verdict->verdict || $this->resolver->resolve($project, $tier)->provider->readsTheCheckout()) {
            return $verdict;
        }

        return Verdict::unreadApproval();
    }

    // Matched on the exception type because the provider's text can change and carries anything (plan T5).
    private function typed(Throwable $failure): Throwable
    {
        return match (true) {
            $failure instanceof RateLimitExceededException => new ProviderRateLimited($failure->getRetryAfter(), $failure),
            $failure instanceof RetryableExceptionInterface => new ProviderFailedTransiently($failure),
            default => $failure,
        };
    }
}
