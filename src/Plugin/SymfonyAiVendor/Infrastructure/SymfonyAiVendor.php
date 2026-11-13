<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Closure;
use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\EveryKeyRejected;
use Intakt\Core\Ai\Contract\EveryProviderOut;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\ReviewServerAccess;
use Intakt\Core\Ai\Contract\ReviewServerReads;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierInvoker;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Review\Contract\ChangesWithoutFindings;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Judge;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\NoReviewServerAccess;
use Intakt\Core\Review\Contract\Reviewer;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\ReviewServerAccesses;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Plugin\Vendor\Contract\NoProviderLeft;
use Intakt\Plugin\Vendor\Contract\ProviderFailedTransiently;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Plugin\Vendor\Contract\ProviderRejectedTheKey;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorAccountBusy;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use Override;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\MaxOutputTokensException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\RetryableExceptionInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Throwable;

// Public because no service injects it before the panel (plan T8), and an unused private service is removed.
#[Autoconfigure(public: true)]
final readonly class SymfonyAiVendor implements Vendor
{
    private const int RETRY_ROOM = 2;

    public function __construct(
        private TierInvoker $tiers,
        private TierResolver $resolver,
        private Reviewer $reviewer,
        private Judge $judge,
        private UsageLedger $usage,
        private VendorAccountLimit $accounts,
        private ClockInterface $clock,
        private ?ReviewServerAccesses $servers = null,
    ) {
    }

    /**
     * @throws VendorAccountBusy
     */
    #[Override]
    public function review(string $project, Tier $tier, ReviewRequest $request): VendorReview
    {
        $answering = $this->answering($project, $tier);
        $substitute = $this->substituteOf($project, $tier, $answering);
        $reserved = fn (ResolvedModel $model, ReviewRequest $asked): UsageReservation => $this->usage->reserve($project, new AnsweringTier($tier, $model), $asked->conversation->round);
        $attempt = fn (ResolvedModel $model, ReviewRequest $asked): Verdict => $this->verdictOn($model, $reserved($model, $asked), $asked);
        $unstreamed = fn (ReviewRequest $asked): Verdict => $this->unstreamedVerdictOn($answering, $reserved($answering, $asked), $asked);
        $first = fn (): Verdict => $attempt($answering, $request);
        $retry = fn (MaxOutputTokensException|ChangesWithoutFindings|IncompleteStreamException $failure): Verdict => match (true) {
            $failure instanceof MaxOutputTokensException => $attempt($this->withMoreRoom($answering, $tier), $this->inNewConversation($request)),
            $failure instanceof ChangesWithoutFindings => $attempt($answering, $this->inNewConversation($request)),
            $answering->provider->streams() && !$failure->getPrevious() instanceof RetryableExceptionInterface => $this->unstreamedAfter($failure, fn (): Verdict => $unstreamed($this->inNewConversation($request))),
            default => throw $failure,
        };

        // Around the whole review on the provider that answers it, so a substitute keeps its own limit and a review refused for a busy account reserves and counts no usage.
        return $this->accounts->run($answering->provider, fn (): VendorReview => new VendorReview($this->blockedWhenCut(fn (): Verdict => $this->retriedOnce($first, $retry)), $substitute));
    }

    // Retried once, because a call stopped by the output limit leaves no verdict and the same room would stop it again, a changes verdict without findings is no verdict either, and a stream cut before its answer completed may be a streaming fault the provider does not have unstreamed; a second failure fails the reviewer. A stream that a subscription CLI cut is not retried, because its call never streamed, and neither is a cut caused by a provider outage, because the outage has already cooled the account down and retrying would turn a blocked reviewer into a waiting one.
    /**
     * @param Closure(): Verdict                                                                          $first
     * @param Closure(MaxOutputTokensException|ChangesWithoutFindings|IncompleteStreamException): Verdict $retry
     */
    private function retriedOnce(Closure $first, Closure $retry): Verdict
    {
        try {
            return $first();
        } catch (MaxOutputTokensException|ChangesWithoutFindings|IncompleteStreamException $failure) {
            return $retry($failure);
        }
    }

    // A second cut keeps the first cut's reason, so the reviewer ends as it did before the retry; any other failure of the retry follows its own rule, so a provider outage is handled as one.
    /**
     * @param Closure(): Verdict $unstreamed
     */
    private function unstreamedAfter(IncompleteStreamException $cut, Closure $unstreamed): Verdict
    {
        try {
            return $unstreamed();
        } catch (IncompleteStreamException) {
            throw $cut;
        }
    }

    // Blocked only once no try is left, so every cut try is recorded in the ledger before the reviewer ends with the last cut's reason.
    /**
     * @param Closure(): Verdict $review
     */
    private function blockedWhenCut(Closure $review): Verdict
    {
        try {
            return $review();
        } catch (IncompleteStreamException $incomplete) {
            return Verdict::incompleteStream($incomplete->getMessage());
        }
    }

    // Twice the tier's default when the mapping sent none, because the model's own default stopped the call then.
    private function withMoreRoom(ResolvedModel $answering, Tier $tier): ResolvedModel
    {
        return $answering->withMaxOutput(self::RETRY_ROOM * ($answering->maxOutput ?? $tier->defaultMaxOutput()));
    }

    // A new conversation, because the stopped one is stored under its id and the store refuses a second start under it.
    private function inNewConversation(ReviewRequest $request): ReviewRequest
    {
        $stopped = $request->conversation;
        $conversation = new ReviewerConversation($stopped->rolePrompt, new UuidV7(UuidV7::generate($this->clock->now())), $stopped->round, $stopped->reviewerId);

        return new ReviewRequest($request->checkout, $request->brief, $conversation);
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
        try {
            $options = $this->serverOptions($answering, $request);
        } catch (NoReviewServerAccess) {
            // Settled with no usage, because the CLI never started; blocked like an API-key reviewer whose toolbox cannot reach the server.
            $this->usage->settle($reservation);

            return Verdict::noTools();
        }

        return $this->verdictThrough(new TierRoutedPlatform($this->tiers, $answering, $options), $reservation, $request);
    }

    // Without server options, because only a subscription CLI is given them and its calls never stream, so they are never retried unstreamed.
    private function unstreamedVerdictOn(ResolvedModel $answering, UsageReservation $reservation, ReviewRequest $request): Verdict
    {
        return $this->verdictThrough(new TierRoutedPlatform($this->tiers, $answering, catalog: new UnstreamedModelCatalog()), $reservation, $request);
    }

    private function verdictThrough(TierRoutedPlatform $platform, UsageReservation $reservation, ReviewRequest $request): Verdict
    {
        try {
            $verdict = $this->reviewer->review($request, $platform, $platform->answering->model);
        } catch (Throwable $failure) {
            // Left unsettled, never settled as well, so the call's usage is counted once, also for a cut stream.
            $this->usage->leaveUnsettled($reservation, $failure, ...$platform->rounds());

            throw $this->typed($failure);
        }

        $this->usage->settle($reservation, ...$platform->rounds());

        return $this->readOrRefused($platform, $this->namingAFinding($verdict));
    }

    // Refused after the usage is settled, because the call itself finished and was paid for.
    private function namingAFinding(Verdict $verdict): Verdict
    {
        return $verdict->asksForChangesWithoutFinding() ? throw ChangesWithoutFindings::fromTheReviewer() : $verdict;
    }

    // Only a subscription CLI is given the review server, because every other provider reaches it through the reviewer's own toolbox; the judge reads no repository.
    /**
     * @return array<string, mixed>
     */
    private function serverOptions(ResolvedModel $answering, ReviewRequest $request): array
    {
        $round = $request->conversation->round;

        if ($answering->provider->readsTheCheckout() || !$round instanceof Uuid || !$this->servers instanceof ReviewServerAccesses) {
            return [];
        }

        return [ReviewServerAccess::OPTION => $this->servers->forRound($round)];
    }

    /**
     * @throws VendorAccountBusy
     */
    #[Override]
    public function judge(string $project, JudgeRequest $request): Judgement
    {
        $answering = $this->answering($project, Tier::Light);

        return $this->accounts->run($answering->provider, fn (): Judgement => $this->judgementOn($answering, $this->usage->reserve($project, new AnsweringTier(Tier::Light, $answering), $request->round), $request));
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

    // Decided here in plain code, because a reviewer that never saw the change can still answer approve; per call, because a subscription CLI reads the change only through the review server this call gave it.
    private function readOrRefused(TierRoutedPlatform $platform, Verdict $verdict): Verdict
    {
        if (Decision::Approve !== $verdict->verdict || $platform->answering->provider->readsTheCheckout()) {
            return $verdict;
        }

        $access = $platform->options[ReviewServerAccess::OPTION] ?? null;

        return $access instanceof ReviewServerAccess && $this->readInThisCall($platform->answering->provider, $access, ...$platform->rounds()) ? $verdict : Verdict::unreadApproval();
    }

    // A codex call must show a read in its own events, because being given the server does not make the CLI use it; claude_code's output is not read for its tool calls, so a call given the server still counts for it (#1278).
    private function readInThisCall(Provider $answeredBy, ReviewServerAccess $access, DeferredResult ...$rounds): bool
    {
        if (Provider::ClaudeCode === $answeredBy) {
            return true;
        }

        return array_any($rounds, static fn (DeferredResult $round): bool => ($raw = $round->getRawResult()) instanceof ReviewServerReads && $raw->readThrough($access));
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
