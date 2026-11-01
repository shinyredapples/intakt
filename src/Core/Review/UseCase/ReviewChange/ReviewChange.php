<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReviewChange;

use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\ChangeReviewRequest;
use Intakt\Core\Review\Contract\ChangeReviews;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\RoundNotNext;
use Intakt\Core\Review\Contract\RoundTokens;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\ReviewSubject;
use Intakt\Core\Review\Domain\ReviewTarget;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Serializer\SerializerInterface;

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
        private SerializerInterface $serializer,
        private ClockInterface $clock,
        private int $waitCeilingSeconds = self::WAIT_CEILING_SECONDS,
    ) {
    }

    public function review(ChangeReviewRequest $request): ChangeReview
    {
        $this->refuseAnotherRound($request);
        $round = $this->awaitJudged($this->start($request));

        return $this->conclude($request, $round);
    }

    private function refuseAnotherRound(ChangeReviewRequest $request): void
    {
        if (null === $request->round) {
            return;
        }

        $next = $this->rounds->latestNumber($request->project, $request->change) + 1;

        if ($next !== $request->round) {
            throw RoundNotNext::for($request->round, $next);
        }
    }

    private function start(ChangeReviewRequest $request): string
    {
        $target = new ReviewTarget(new ReviewSubject($request->project, $request->checkoutRoot, $request->task), $request->identity);
        $handled = $this->commands->dispatch(new RunReviewRound($target, $request->panel, $request->change))->last(HandledStamp::class);
        $roundId = $handled?->getResult();
        assert(is_string($roundId));

        return $roundId;
    }

    // The last state seen is returned at the ceiling, so what the reviewers did finish is still reported.
    private function awaitJudged(string $roundId): ReviewRound
    {
        $deadline = $this->clock->now()->modify('+'.$this->waitCeilingSeconds.' seconds');
        $round = $this->rounds->stored($roundId);

        while (RoundState::Judged !== $round->state() && $this->clock->now() < $deadline) {
            $this->clock->sleep(self::POLL_SECONDS);
            $round = $this->rounds->stored($roundId);
        }

        return $round;
    }

    private function conclude(ChangeReviewRequest $request, ReviewRound $round): ChangeReview
    {
        $votes = array_map($this->voteOf(...), $round->reviewers());
        $judged = RoundState::Judged === $round->state();
        $decision = $judged ? $this->decision->of($request->panel->quorum, $votes) : Decision::Blocked;
        $roundId = $round->id()->toRfc4122();

        return new ChangeReview(
            $roundId,
            $round->number(),
            $decision,
            $this->verdictsOf($round),
            $judged ? $this->actListOf($round) : [],
            $this->ledger->tokensSince($roundId, $request->project),
            $this->reasonsOf($round, $judged),
        );
    }

    // A reviewer without a verdict votes as blocked, so a failed review can never help a quorum.
    private function voteOf(RoundReviewer $reviewer): PanelVerdict
    {
        $verdict = $this->verdictOf($reviewer) ?? new Verdict(Decision::Blocked, [], [], []);

        return new PanelVerdict($reviewer->tier(), $verdict);
    }

    /**
     * @return list<PanelVerdict>
     */
    private function verdictsOf(ReviewRound $round): array
    {
        $given = [];

        foreach ($round->reviewers() as $reviewer) {
            $verdict = $this->verdictOf($reviewer);

            if ($verdict instanceof Verdict) {
                $given[] = new PanelVerdict($reviewer->tier(), $verdict);
            }
        }

        return $given;
    }

    private function verdictOf(RoundReviewer $reviewer): ?Verdict
    {
        $json = $reviewer->verdictJson();

        return null === $json ? null : $this->serializer->deserialize($json, Verdict::class, 'json');
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
    private function reasonsOf(ReviewRound $round, bool $judged): array
    {
        $reasons = $judged ? [] : ['The round did not finish within '.$this->waitCeilingSeconds.' seconds: start workers with "messenger:consume async" and review again.'];

        foreach ($round->reviewers() as $reviewer) {
            if (null !== $reviewer->failure()) {
                $reasons[] = $reviewer->roleName().': '.$reviewer->failure();
            }
        }

        return $reasons;
    }
}
