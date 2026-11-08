<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReviewChange;

use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\ChangeReviewRequest;
use Intakt\Core\Review\Contract\ChangeReviews;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\IgnoredProgress;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\ReviewProgress;
use Intakt\Core\Review\Contract\RoundNotNext;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\ReviewSubject;
use Intakt\Core\Review\Domain\ReviewTarget;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\UseCase\ReadVerdicts\StoredVerdicts;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\ShadowCeilingReached;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

// Queues a round and waits for the workers to finish it, because reviewing and judging run in the workers and not in the caller.
final readonly class ReviewChange implements ChangeReviews
{
    // Past the hour one reviewer may hold a round plus a judge call, so a slow but healthy round is not given up on.
    public const int WAIT_CEILING_SECONDS = 7200;

    private const int POLL_SECONDS = 1;

    public function __construct(
        private MessageBusInterface $commands,
        private ReviewRounds $rounds,
        private StoredFindings $findings,
        private RoundLedgerReading $ledger,
        private ChangeDecision $decision,
        private StoredVerdicts $verdicts,
        private ClockInterface $clock,
        private int $waitCeilingSeconds = self::WAIT_CEILING_SECONDS,
    ) {
    }

    public function review(ChangeReviewRequest $request, ReviewProgress $progress = new IgnoredProgress()): ChangeReview
    {
        $round = $this->awaitJudged($this->start($request), $progress);

        return $this->conclude($request, $round);
    }

    public function start(ChangeReviewRequest $request): string
    {
        $target = new ReviewTarget(new ReviewSubject($request->project, $request->checkoutRoot, $request->task, $request->diff), $request->identity);
        $handled = $this->dispatched(new RunReviewRound($target, $request->panel, $request->round))->last(HandledStamp::class);
        $roundId = $handled?->getResult();
        assert(is_string($roundId));

        return $roundId;
    }

    // Unwrapped, because a round another caller opened first or a shadow round held above its ceiling is the caller's to report in its own words, not a failed handler.
    private function dispatched(RunReviewRound $command): Envelope
    {
        try {
            return $this->commands->dispatch($command);
        } catch (HandlerFailedException $failed) {
            throw array_first($failed->getWrappedExceptions(RoundNotNext::class, true)) ?? array_first($failed->getWrappedExceptions(ShadowCeilingReached::class, true)) ?? $failed;
        }
    }

    // The last state seen is returned at the ceiling, so what the reviewers did finish is still reported.
    private function awaitJudged(string $roundId, ReviewProgress $progress): ReviewRound
    {
        $deadline = $this->clock->now()->modify('+'.$this->waitCeilingSeconds.' seconds');
        $round = $this->rounds->stored($roundId);
        $progress->reached($round->progress());

        while (RoundState::Judged !== $round->state() && $this->clock->now() < $deadline) {
            $this->clock->sleep(self::POLL_SECONDS);
            $round = $this->rounds->stored($roundId);
            $progress->reached($round->progress());
        }

        return $round;
    }

    private function conclude(ChangeReviewRequest $request, ReviewRound $round): ChangeReview
    {
        $review = $this->readFrom($request, $round);

        return RoundState::Judged === $round->state() ? $review : $this->unfinished($review);
    }

    private function readFrom(ChangeReviewRequest $request, ReviewRound $round): ChangeReview
    {
        $roundId = $round->id()->toRfc4122();

        return new ChangeReview(
            $roundId,
            $round->number(),
            $this->decision->of($request->panel->quorum, array_map($this->voteOf(...), $round->reviewers())),
            $this->verdicts->of($round),
            $this->actListOf($round),
            $this->ledger->tokensOf($roundId),
            $this->ledger->costOf($roundId),
            $this->reasonsOf($round),
        );
    }

    private function unfinished(ChangeReview $review): ChangeReview
    {
        return $review->blockedFor('The round did not finish within '.$this->waitCeilingSeconds.' seconds: start workers with "messenger:consume async" and review again.');
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
        $act = $this->ledger->actIdsOf($round->id()->toRfc4122());

        return array_values(array_filter($this->findings->reportedIn($round), static fn (Finding $finding): bool => in_array($finding->id, $act, true)));
    }

    /**
     * @return list<string>
     */
    private function reasonsOf(ReviewRound $round): array
    {
        $reasons = [];

        foreach ($round->reviewers() as $reviewer) {
            if (null !== $reviewer->failure()) {
                $reasons[] = $reviewer->roleName().': '.$reviewer->failure();
            }
        }

        return $reasons;
    }
}
