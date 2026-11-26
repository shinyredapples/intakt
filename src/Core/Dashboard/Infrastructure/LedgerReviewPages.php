<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Build\Contract\BuildStep;
use Intakt\Core\Build\Contract\BuildStepOutcome;
use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\BuildTask;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\Mentions;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\CountedQuotes;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\ReviewerStarted;
use Intakt\Core\Review\Contract\RoundCosts;
use Intakt\Core\Review\Contract\RoundEndings;
use Symfony\Component\Clock\ClockInterface;

final readonly class LedgerReviewPages
{
    public function __construct(
        private ClockInterface $clock,
        private Ledger $ledger,
        private RoundPayloads $payloads,
        private RoundCosts $costs,
        private ChangeDecision $decision,
        private FailureInWords $failures,
        private RoundEndings $endings,
        private ReplayMarks $marks,
        private LedgerBuildSteps $buildSteps = new LedgerBuildSteps(),
        private CountedQuotes $quotes = new CountedQuotes(),
    ) {
    }

    // Null for a round the ledger never started readably, so the page answers 404 instead of an empty review. Read from the starts, then the rows that name the review's rounds, then those that name their calls, because a settled call's row names only its call; never the whole ledger, because each judged state's live update reads it too.
    public function reviewOf(string $round): ?Review
    {
        $rounds = $this->rounds($this->ledger->readOfKind(LedgerEventKind::ReviewStarted));
        $members = $rounds->roundsOfTheReviewOf($round);

        if ([] === $members) {
            return null;
        }

        $added = $this->addUnseen($rounds, $this->ledger->readMentioning(new Mentions(...$members)), []);
        $this->addUnseen($rounds, $this->ledger->readMentioning(new Mentions(...$rounds->callsOf($members))), $added);

        return $rounds->reviewOf($round);
    }

    // Null on a fresh install; read from the starts alone, so a live update can learn whether a round is the install's first before it builds and prices that review.
    public function firstRound(): ?string
    {
        return $this->rounds($this->ledger->readOfKind(LedgerEventKind::ReviewStarted))->firstRound();
    }

    // Null on a fresh install, because then no review has run.
    public function firstReview(): ?Review
    {
        return array_first($this->rounds($this->ledger->readInOrder())->reviews());
    }

    // The review whose first round started last, because a project may hold the reviews of more than one change.
    public function latestReviewOf(string $project): ?Review
    {
        return array_last(array_filter($this->rounds($this->ledger->readInOrder())->reviews(), static fn (Review $review): bool => $review->project === $project));
    }

    /**
     * @return list<ReviewedRound>
     */
    public function listedRoundsInStartOrder(): array
    {
        return $this->rounds($this->ledger->readInOrder())->listedInStartOrder();
    }

    // Complete with each round's cost and replay mark, which the list leaves out, because the trends count them; one read serves the figures and the board's cards alike.
    /**
     * @return list<Review>
     */
    public function reviewsOf(RecordedEvents $events): array
    {
        return $this->rounds($events)->reviews();
    }

    // Read from the round's own reviewer starts (the subject index), because a live update goes out for each streamed text and must not read the starts of every other round; the latest start wins, as on the page, and null means the reviewer has not started in that round.
    public function startedReviewerOf(string $round, string $reviewer): ?ReviewerOutcome
    {
        return $this->latestStartIn($this->ledger->readOfKindAbout(LedgerEventKind::ReviewerStarted, $round), $round, $reviewer)
            // Only starts stored before the ledger had a subject lack one, so this set stops growing at the upgrade and a reviewer running across it keeps its heading.
            ?? $this->latestStartIn($this->ledger->readOfKindWithoutSubject(LedgerEventKind::ReviewerStarted), $round, $reviewer);
    }

    /**
     * @return list<string>
     */
    public function builtProjects(): array
    {
        return array_values(array_unique(array_map(static fn (array $step): string => $step['project'], $this->buildSteps->in($this->ledger->readInOrder()))));
    }

    public function board(): Board
    {
        return $this->boardOf($this->ledger->readInOrder());
    }

    public function boardOf(RecordedEvents $events): Board
    {
        return new Board($this->clock->now(), $this->reviewsOf($events), $this->tasks($events));
    }

    private function latestStartIn(RecordedEvents $starts, string $round, string $reviewer): ?ReviewerOutcome
    {
        $started = null;

        foreach ($starts as $recorded) {
            $row = $this->payloads->reviewerRowOf($recorded);

            if ($row instanceof ReviewerStarted && $row->round === $round && $row->reviewer === $reviewer) {
                $started = new ReviewerOutcome($row->reviewer, $row->role, $row->tier->value, $recorded->recordedAt)->restarted($recorded->recordedAt, $row->model);
            }
        }

        return $started;
    }

    // A task is in flight while its latest step passed short of the last step, because a build run stops at its first failed step and ends with its pull request step, so either leaves the task at rest (#2732).
    /**
     * @return list<BuildTask>
     */
    private function tasks(RecordedEvents $events): array
    {
        return array_map(
            static fn (array $step): BuildTask => new BuildTask($step['project'], $step['task'], BuildStepOutcome::Passed->value === $step['outcome'] && array_last(BuildStep::cases())->value !== $step['step']),
            $this->buildSteps->in($events),
        );
    }

    private function rounds(RecordedEvents $events): LedgerRounds
    {
        $rounds = new LedgerRounds($this->payloads, $this->costs, $this->decision, $this->failures, $this->endings, $this->marks, $this->quotes);

        foreach ($events as $recorded) {
            $rounds->add($recorded);
        }

        return $rounds;
    }

    // A row both reads find is added once, because a call's tokens and a reviewer's moves count each time they are added; a start found again changes nothing, because the first one counts.
    /**
     * @param array<string, true> $seen
     *
     * @return array<string, true>
     */
    private function addUnseen(LedgerRounds $rounds, RecordedEvents $events, array $seen): array
    {
        foreach ($events as $recorded) {
            $id = $recorded->id->toRfc4122();

            if (!isset($seen[$id])) {
                $rounds->add($recorded);
                $seen[$id] = true;
            }
        }

        return $seen;
    }
}
