<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\EveryKeyRejected;
use Intakt\Core\Ai\Contract\EveryProviderOut;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierInvoker;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Judge;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\Reviewer;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Plugin\Vendor\Contract\NoProviderLeft;
use Intakt\Plugin\Vendor\Contract\ProviderFailedTransiently;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Plugin\Vendor\Contract\ProviderRejectedTheKey;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorAccountBusy;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use Symfony\AI\Platform\Exception\AuthenticationException;
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
        private Judge $judge,
        private UsageLedger $usage,
        private VendorAccountLimit $accounts,
    ) {
    }

    /**
     * @throws VendorAccountBusy
     */
    public function review(string $project, Tier $tier, ReviewRequest $request): VendorReview
    {
        $answering = $this->answering($project, $tier);
        $substitute = $this->substituteOf($project, $tier, $answering);

        // Around the whole review on the provider that answers it, so a substitute keeps its own limit and a review refused for a busy account reserves and counts no usage.
        return $this->accounts->run($answering->provider, fn (): VendorReview => new VendorReview(
            $this->readOrRefused($answering->provider, $this->verdictOn($answering, $this->usage->reserve($project, $tier), $request)),
            $substitute,
        ));
    }

    private function answering(string $project, Tier $tier): ResolvedModel
    {
        try {
            return $this->tiers->answering($project, $tier);
        } catch (EveryAccountCoolingDown|EveryProviderOut|EveryKeyRejected $out) {
            throw $this->typed($out);
        }
    }

    // Read from the model chosen before the review, never from its text, so a reviewer cannot claim to be a substitute; against the configured model, because a disabled one is substituted too.
    private function substituteOf(string $project, Tier $tier, ResolvedModel $answering): ?Provider
    {
        return $this->resolver->configured($project, $tier)->provider === $answering->provider ? null : $answering->provider;
    }

    private function verdictOn(ResolvedModel $answering, UsageReservation $reservation, ReviewRequest $request): Verdict
    {
        $platform = new TierRoutedPlatform($this->tiers, $answering);

        try {
            $verdict = $this->reviewer->review($request, $platform, $answering->model);
        } catch (IncompleteStreamException $incomplete) {
            // Left unsettled, never settled as well, so the call's usage is counted once.
            $this->usage->leaveUnsettled($reservation, $incomplete, ...$platform->rounds());

            return Verdict::incompleteStream($incomplete->getMessage());
        } catch (Throwable $failure) {
            $this->usage->leaveUnsettled($reservation, $failure, ...$platform->rounds());

            throw $this->typed($failure);
        }

        $this->usage->settle($reservation, ...$platform->rounds());

        return $verdict;
    }

    /**
     * @throws VendorAccountBusy
     */
    public function judge(string $project, JudgeRequest $request): Judgement
    {
        $answering = $this->answering($project, Tier::Light);

        return $this->accounts->run($answering->provider, fn (): Judgement => $this->judgementOn($answering, $this->usage->reserve($project, Tier::Light), $request));
    }

    private function judgementOn(ResolvedModel $answering, UsageReservation $reservation, JudgeRequest $request): Judgement
    {
        $platform = new TierRoutedPlatform($this->tiers, $answering);

        try {
            $judgement = $this->judge->judge($request, $platform, $answering->model);
        } catch (IncompleteStreamException $incomplete) {
            // A cut-off stream is an answer that cannot be read, so every blocking finding acts.
            $this->usage->leaveUnsettled($reservation, $incomplete, ...$platform->rounds());

            return Judgement::everyBlockingFindingActs($request->findings);
        } catch (Throwable $failure) {
            $this->usage->leaveUnsettled($reservation, $failure, ...$platform->rounds());

            throw $this->typed($failure);
        }

        $this->usage->settle($reservation, ...$platform->rounds());

        return $judgement;
    }

    // Decided here in plain code, because a reviewer that never saw the change can still answer approve.
    private function readOrRefused(Provider $answeredBy, Verdict $verdict): Verdict
    {
        if (Decision::Approve !== $verdict->verdict || $answeredBy->readsTheCheckout()) {
            return $verdict;
        }

        return Verdict::unreadApproval();
    }

    // Matched on the exception type because the provider's text can change and carries anything (plan T5).
    private function typed(Throwable $failure): Throwable
    {
        return match (true) {
            $failure instanceof RateLimitExceededException => new ProviderRateLimited($failure->getRetryAfter(), $failure),
            $failure instanceof EveryProviderOut => new NoProviderLeft($failure->getMessage(), $failure),
            $failure instanceof AuthenticationException => new ProviderRejectedTheKey($failure),
            $failure instanceof EveryAccountCoolingDown => new ProviderRateLimited($failure->secondsUntilAnAccountIsFree, $failure),
            $failure instanceof RetryableExceptionInterface => new ProviderFailedTransiently($failure),
            default => $failure,
        };
    }
}
