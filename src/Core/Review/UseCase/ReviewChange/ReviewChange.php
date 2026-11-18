<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReviewChange;

use Intakt\Core\Ai\Contract\Tier;
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
use Intakt\Core\Review\Contract\RoundNotNext;
use Intakt\Core\Review\Contract\RoundTierNotMapped;
use Intakt\Core\Review\Contract\Verdict;
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
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
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
        $handled = $this->dispatched(new RunReviewRound($target, $request->panel, $request->round))->last(HandledStamp::class);
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

    // Unwrapped, because a round another caller opened first is the caller's to report in its own words, not a failed handler.
    private function dispatched(RunReviewRound $command): Envelope
    {
        try {
            return $this->commands->dispatch($command);
        } catch (HandlerFailedException $failed) {
            throw array_first($failed->getWrappedExceptions(RoundNotNext::class, true)) ?? $failed;
        }
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

        return $this->decision->of($request->panel->quorum, array_map($this->voteOf(...), $round->reviewers()));
    }

    private function unfinished(ChangeReview $review, int $seconds): ChangeReview
    {
        return $review->blockedFor(RoundEnds::NoOutcomeInTime, $this->reasons->noOutcomeInTime($seconds));
    }

    // A reviewer without a verdict votes as blocked, so a failed review can never help a quorum.
    private function voteOf(RoundReviewer $reviewer): PanelVerdict
    {
        $verdict = $this->verdicts->ofReviewer($reviewer) ?? new Verdict(Decision::Blocked, [], [], []);

        return new PanelVerdict($reviewer->tier(), $verdict);
    }

    /**
     * @return list<Finding>
     */
    private function actListOf(ReviewRound $round): array
    {
        $id = $round->id()->toRfc4122();

        return $this->findings->withJudgeNotes($this->findings->actListOf($round, $this->ledger->actIdsOf($id), $this->ledger->sameCauseOf($id)), $this->ledger->judgeNotesOf($id));
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

    /**
     * @return list<string>
     */
    private function reasonsOf(ReviewRound $round): array
    {
        $reasons = ReplayMark::Replay === $round->replayMark() ? [$this->reasons->replay()] : [];

        foreach ($round->reviewers() as $reviewer) {
            if (null !== $reviewer->failure()) {
                $reasons[] = $reviewer->roleName().' reviewer failed, '.$this->failures->of($reviewer->failure());
            }
        }

        return $reasons;
    }
}
