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
use Intakt\Core\Ai\Contract\TierNotMapped;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Review\Contract\ChangesWithoutFindings;
use Intakt\Core\Review\Contract\Evidence;
use Intakt\Core\Review\Contract\Judge;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\NoReviewServerAccess;
use Intakt\Core\Review\Contract\Reviewer;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\ReviewServerAccesses;
use Intakt\Core\Review\Contract\StreamCutBy;
use Intakt\Core\Review\Contract\StreamEndedEarly;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictUnreadable;
use Intakt\Plugin\Vendor\Contract\ProviderUnreachable;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorAccountBusy;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use Override;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\MaxOutputTokensException;
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
        private ReviewServerAccesses $servers,
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
        $reserved = fn (ResolvedModel $model, ReviewRequest $asked): UsageReservation => $this->usage->reserveForReviewer($project, new AnsweringTier($tier, $model), $asked->conversation);
        $attempt = fn (ResolvedModel $model, ReviewRequest $asked): Verdict => $this->verdictOn($model, $reserved($model, $asked), $asked);
        $unstreamed = fn (ReviewRequest $asked): Verdict => $this->unstreamedVerdictOn($answering, $reserved($answering, $asked), $asked);
        $first = fn (): Verdict => $attempt($answering, $request);
        $retry = fn (MaxOutputTokensException|VerdictUnreadable|ChangesWithoutFindings|ProviderUnreachable|IncompleteStreamException $failure): Verdict => match (true) {
            $failure instanceof MaxOutputTokensException, $failure instanceof VerdictUnreadable => $attempt($this->withMoreRoom($answering, $tier), $this->inNewConversation($request)),
            $failure instanceof ChangesWithoutFindings => $attempt($answering, $this->askedToNameFindings($request)),
            $failure instanceof ProviderUnreachable => $attempt($answering, $this->inNewConversation($request)),
            $answering->provider->streams() && !$failure->getPrevious() instanceof RetryableExceptionInterface => $this->unstreamedAfter($failure, fn (): Verdict => $unstreamed($this->inNewConversation($request))),
            default => throw $failure,
        };

        // Around the whole review on the provider that answers it, so a substitute keeps its own limit and a review refused for a busy account reserves and counts no usage.
        return $this->accounts->run($answering->provider, fn (): VendorReview => new VendorReview($this->blockedWhenCut($answering, fn (): Verdict => $this->retriedOnce($first, $retry)), $substitute));
    }

    // Retried once, because a call stopped by the output limit or an answer that is no verdict leaves nothing to judge and the same room could stop it again, a changes verdict without findings is no verdict either, an endpoint that could not be reached is often reached on the next call, and a stream cut before its answer completed may be a streaming fault the provider does not have unstreamed; a second failure fails the reviewer. A stream that a subscription CLI cut is not retried, because its call never streamed, and neither is a cut caused by a provider outage, because the outage has already cooled the account down and retrying would turn a blocked reviewer into a waiting one.
    /**
     * @param Closure(): Verdict                                                                                                                $first
     * @param Closure(MaxOutputTokensException|VerdictUnreadable|ChangesWithoutFindings|ProviderUnreachable|IncompleteStreamException): Verdict $retry
     */
    private function retriedOnce(Closure $first, Closure $retry): Verdict
    {
        try {
            return $first();
        } catch (MaxOutputTokensException|VerdictUnreadable|ChangesWithoutFindings|ProviderUnreachable|IncompleteStreamException $failure) {
            return $retry($failure);
        }
    }

    // A second cut keeps the first cut's reason, so the reviewer ends as it did before the retry; a provider failure of the retry follows its own rule, so an outage is handled as one, and no other failure of it is retried, because retriedOnce() catches only the first try's.
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
    private function blockedWhenCut(ResolvedModel $answering, Closure $review): Verdict
    {
        try {
            return $review();
        } catch (IncompleteStreamException $incomplete) {
            return Verdict::incompleteStream($incomplete->getMessage(), $this->evidenceOfCut($answering, $incomplete));
        }
    }

    // Kept on the finding, because the message alone cannot tell a provider that cut the stream from a platform or an Intakt check that misread a complete one (issue #1964); every try asks the same provider and model.
    private function evidenceOfCut(ResolvedModel $answering, IncompleteStreamException $cut): Evidence
    {
        return new Evidence(
            sprintf('the call to %s, model %s', $answering->provider->value, $answering->model),
            $cut instanceof StreamEndedEarly ? $cut->described() : sprintf('cut by: %s, outside a counted stream', StreamCutBy::Platform->value),
        );
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
        $conversation = new ReviewerConversation($stopped->rolePrompt, new UuidV7(UuidV7::generate($this->clock->now())), $stopped->round, $stopped->reviewerId, askedAgain: true);

        return new ReviewRequest($request->checkout, $request->brief, $conversation);
    }

    // Told what its answer lacked, so the same reviewer names its findings or approves instead of repeating an empty changes answer.
    private function askedToNameFindings(ReviewRequest $request): ReviewRequest
    {
        $refused = $request->conversation;
        $conversation = new ReviewerConversation(
            $refused->rolePrompt,
            new UuidV7(UuidV7::generate($this->clock->now())),
            $refused->round,
            $refused->reviewerId,
            askedAgain: true,
            askedToNameFindings: true,
        );

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
        } catch (VerdictUnreadable $unreadable) {
            // Settled, because the call ended with an answer and its usage is known.
            $this->usage->settle($reservation, ...$platform->rounds());

            throw $unreadable;
            // Boundary: leaves the call unsettled and rethrows it typed, because any provider failure must reach the callers as a vendor failure.
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
        return $verdict->withholdsApprovalWithoutFinding() ? throw ChangesWithoutFindings::fromTheReviewer() : $verdict;
    }

    // Only a subscription CLI is given the review server, because every other provider reaches it through the reviewer's own toolbox; the judge reads no repository.
    /**
     * @return array<string, mixed>
     */
    private function serverOptions(ResolvedModel $answering, ReviewRequest $request): array
    {
        $round = $request->conversation->round;

        if ($answering->provider->readsTheCheckout() || !$round instanceof Uuid) {
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
        $answering = $this->answering($project, self::JUDGE_TIER);

        return $this->accounts->run($answering->provider, fn (): Judgement => $this->judgementOn($answering, $this->usage->reserve($project, new AnsweringTier(self::JUDGE_TIER, $answering), $request->round), $request));
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
            // Boundary: leaves the call unsettled and rethrows it typed, because any provider failure must reach the callers as a vendor failure.
        } catch (Throwable $failure) {
            $this->usage->leaveUnsettled($reservation, $failure, ...$platform->rounds());

            throw $this->typed($failure);
        }

        $this->usage->settle($reservation, ...$platform->rounds());

        return $judgement;
    }

    // Read from the mapping that throws TierNotMapped on a call, so the answer and the call never disagree; it covers only the mapping, not whether a provider is available, so a tier whose provider is disabled still counts even when no substitute answers for it.
    #[Override]
    public function mapsAModelTo(string $project, Tier $tier): bool
    {
        try {
            $this->resolver->configured($project, $tier);
        } catch (TierNotMapped) {
            return false;
        }

        return true;
    }

    // Decided here in plain code, because a reviewer that never saw the change can still answer approve; per call, because a subscription CLI reads the change only through the review server this call gave it.
    private function readOrRefused(TierRoutedPlatform $platform, Verdict $verdict): Verdict
    {
        if (!$verdict->mayCountAsApproval() || $platform->answering->provider->readsTheCheckout()) {
            return $verdict;
        }

        $access = $platform->options[ReviewServerAccess::OPTION] ?? null;

        return $access instanceof ReviewServerAccess && $this->readInThisCall($access, ...$platform->rounds()) ? $verdict : Verdict::unreadApproval();
    }

    // The call must show a read in its own events, because being given the server does not make the CLI use it.
    private function readInThisCall(ReviewServerAccess $access, DeferredResult ...$rounds): bool
    {
        return array_any($rounds, static fn (DeferredResult $round): bool => ($raw = $round->getRawResult()) instanceof ReviewServerReads && $raw->readThrough($access));
    }

    private function typed(Throwable $failure): Throwable
    {
        return TypedProviderFailure::of($failure);
    }
}
