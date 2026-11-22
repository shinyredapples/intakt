<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReviewChange;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\BlockReasons;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\ChangeReviewRequest;
use Intakt\Core\Review\Contract\ChangeReviews;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\IgnoredProgress;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\ReviewerOutcome;
use Intakt\Core\Review\Contract\ReviewProgress;
use Intakt\Core\Review\Contract\RoundEnds;
use Intakt\Core\Review\Contract\RoundTierNotMapped;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\ReviewSubject;
use Intakt\Core\Review\Domain\ReviewTarget;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\UseCase\JudgeRound\FailedJudgings;
use Intakt\Core\Review\UseCase\ReadVerdicts\StoredVerdicts;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

// Queues a round and waits for the workers to finish it, because reviewing and judging run in the workers and not in the caller.
final readonly class ReviewChange implements ChangeReviews
{
    private const int POLL_SECONDS = 1;

    public function __construct(
        private MessageBusInterface $commands,
        private ReviewRounds $rounds,
        private StoredFindings $findings,
        private RoundLedgerReading $ledger,
        private ChangeDecision $decision,
        private StoredVerdicts $verdicts,
        private ClockInterface $clock,
        private FailureInWords $failures,
        private Vendor $vendor,
        private FailedJudgings $failedJudgings,
        private UpperWait $upperWait = new UpperWait(),
        private RoundEndReasons $reasons = new RoundEndReasons(),
    ) {
    }

    public function review(ChangeReviewRequest $request, ReviewProgress $progress = new IgnoredProgress()): ChangeReview
    {
        $seconds = $this->upperWait->secondsFor($request->panel);
        $round = $this->awaitJudged($this->start($request), $seconds, $progress);

        return $this->conclude($request, $round, $seconds);
    }

    public function start(ChangeReviewRequest $request): string
    {
        $this->refuseUnmappedTiers($request);
        $target = new ReviewTarget(new ReviewSubject($request->project, $request->checkoutRoot, $request->task, $request->diff), $request->identity);
        $handled = $this->commands->dispatch(new RunReviewRound($target, $request->panel, $request->round))->last(HandledStamp::class);
        $roundId = $handled?->getResult();
        assert(is_string($roundId));

        return $roundId;
    }

    // Asked before the round opens, so a tier without a model refuses the round instead of failing the judge after every reviewer answered.
    private function refuseUnmappedTiers(ChangeReviewRequest $request): void
    {
        foreach ($this->tiersNeeded($request->panel) as $tier) {
            if (!$this->vendor->mapsAModelTo($request->project, $tier)) {
                throw new RoundTierNotMapped($request->project, $tier);
            }
        }
    }

    /**
     * @return list<Tier>
     */
    private function tiersNeeded(Panel $panel): array
    {
        $reviewerTiers = array_map(static fn (PanelReviewer $reviewer): Tier => $reviewer->model->tier, $panel->reviewers);

        return [...$reviewerTiers, Vendor::JUDGE_TIER];
    }

    // The last state seen is returned at the upper wait, so what the reviewers did finish is still reported.
    private function awaitJudged(string $roundId, int $seconds, ReviewProgress $progress): ReviewRound
    {
        $deadline = $this->clock->now()->modify('+'.$seconds.' seconds');
        $round = $this->rounds->stored($roundId);
        $progress->reached($round->progress());

        while (!$this->hasEnded($round) && $this->clock->now() < $deadline) {
            $this->clock->sleep(self::POLL_SECONDS);
            $round = $this->rounds->stored($roundId);
            $progress->reached($round->progress());
        }

        return $round;
    }

    private function hasEnded(ReviewRound $round): bool
    {
        return RoundState::Judged === $round->state() || $this->judgingFailed($round);
    }

    // Only a complete round is judged, so the failure transport is not read while reviewers still work.
    private function judgingFailed(ReviewRound $round): bool
    {
        return RoundState::Complete === $round->state() && $this->failedJudgings->hasFailed($round->id()->toRfc4122());
    }

    private function conclude(ChangeReviewRequest $request, ReviewRound $round, int $seconds): ChangeReview
    {
        $review = $this->readFrom($request, $round);

        return match (true) {
            RoundState::Judged === $round->state() => $review,
            $this->judgingFailed($round) => $review->blockedFor(RoundEnds::JudgingFailed, $this->reasons->judgingFailed()),
            default => $this->unfinished($review, $seconds),
        };
    }

    private function readFrom(ChangeReviewRequest $request, ReviewRound $round): ChangeReview
    {
        $roundId = $round->id()->toRfc4122();

        return new ChangeReview(
            $roundId,
            $round->number(),
            $this->decisionOf($request, $round),
            $this->verdicts->of($round),
            $this->actListOf($round),
            $this->ledger->tokensOf($roundId),
            $this->ledger->costOf($roundId),
            $this->reasonsOf($round),
            RoundEnds::Judged,
            $this->outcomesOf($roundId, $round),
        );
    }

    // A replay plays a recorded review back, so its verdicts were never given on this change and help no quorum.
    private function decisionOf(ChangeReviewRequest $request, ReviewRound $round): Decision
    {
        if (ReplayMark::Replay === $round->replayMark()) {
            return Decision::Blocked;
        }

        $counted = $this->ledger->votesOf($round->id()->toRfc4122());

        return $this->decision->of($request->panel->quorum, array_map(fn (RoundReviewer $reviewer): PanelVerdict => $this->voteOf($reviewer, $counted), $round->reviewers()));
    }

    private function unfinished(ChangeReview $review, int $seconds): ChangeReview
    {
        return $review->blockedFor(RoundEnds::NoOutcomeInTime, $this->reasons->noOutcomeInTime($seconds));
    }

    // A reviewer without a verdict votes as blocked, so a failed review can never help a quorum; a verdict counts as the judge step recorded it, and as it was given when that step recorded no vote for it (a round judged before votes were counted).
    /**
     * @param array<string, Decision> $counted
     */
    private function voteOf(RoundReviewer $reviewer, array $counted): PanelVerdict
    {
        $given = $this->verdicts->ofReviewer($reviewer)?->verdict;
        $vote = $given instanceof Decision ? $counted[$reviewer->id()->toRfc4122()] ?? $given : Decision::Blocked;

        return new PanelVerdict($reviewer->tier(), new Verdict($vote, [], [], []));
    }

    /**
     * @return list<Finding>
     */
    private function actListOf(ReviewRound $round): array
    {
        return $this->findings->actListOf($round, $this->ledger->judgeDoneOf($round->id()->toRfc4122()));
    }

    /**
     * @return list<ReviewerOutcome>
     */
    private function outcomesOf(string $roundId, ReviewRound $round): array
    {
        return array_map(fn (RoundReviewer $reviewer): ReviewerOutcome => $this->outcomeOf($roundId, $reviewer), $round->reviewers());
    }

    private function outcomeOf(string $roundId, RoundReviewer $reviewer): ReviewerOutcome
    {
        $reviewerId = $reviewer->id()->toRfc4122();
        $failure = $reviewer->failure();

        return new ReviewerOutcome(
            $reviewer->roleName(),
            $reviewer->tier(),
            $this->verdicts->ofReviewer($reviewer),
            null === $failure ? null : $this->failures->of($failure),
            $this->ledger->tokensOfReviewer($roundId, $reviewerId),
            $this->ledger->millisecondsOfReviewer($reviewerId),
            $this->ledger->modelsOfReviewer($roundId, $reviewerId),
        );
    }

    private function reasonsOf(ReviewRound $round): BlockReasons
    {
        $reasons = ReplayMark::Replay === $round->replayMark() ? [$this->reasons->replay()] : [];

        foreach ($round->reviewers() as $reviewer) {
            $reason = $this->reasonOf($reviewer);

            if (null !== $reason) {
                $reasons[] = $reviewer->roleName().' reviewer '.$reason;
            }
        }

        return new BlockReasons(...$reasons);
    }

    // Blocked and failed are named apart, so the line says what the dashboard's chip says for the same reviewer.
    private function reasonOf(RoundReviewer $reviewer): ?string
    {
        $failure = $reviewer->failure();

        if (null !== $failure) {
            return (ReviewerState::Blocked === $reviewer->state() ? 'blocked, ' : 'failed, ').$this->failures->of($failure);
        }

        $blocked = $this->verdicts->ofReviewer($reviewer)?->blockedReason();

        return null === $blocked ? null : 'blocked, '.$blocked;
    }
}
