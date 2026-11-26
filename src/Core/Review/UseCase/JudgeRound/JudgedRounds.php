<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\JudgedFindings;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\CountedVote;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\Infrastructure\AddedLinesInCheckout;
use Intakt\Core\Review\Infrastructure\ChangeText;
use Intakt\Core\Review\Infrastructure\DiffHunks;
use Intakt\Core\Review\Infrastructure\DiffPaths;
use Intakt\Core\Review\UseCase\ReadVerdicts\StoredVerdicts;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;

final readonly class JudgedRounds
{
    public function __construct(
        private ReviewRounds $rounds,
        private StoredFindings $findings,
        private RoundLedger $ledger,
        private ChangeText $change,
        private DiffHunks $hunks,
        private AddedLinesInCheckout $addedLines,
        private DiffPaths $paths,
        private StoredVerdicts $verdicts,
        private FailedJudgings $failedJudgings,
        private RecordRecurringP1s $recurringP1s,
    ) {
    }

    /**
     * @return list<string>
     */
    public function filesOf(ReviewRound $round): array
    {
        return $this->paths->in($round->diff());
    }

    /**
     * @return list<array{string, int, int}>
     */
    public function hunksOf(ReviewRound $round): array
    {
        return $this->hunks->in($round->diff());
    }

    /**
     * @return list<array{string, int, string}>
     */
    public function addedLinesOf(ReviewRound $round): array
    {
        return $this->addedLines->of($round->diff(), $round->checkoutRoot());
    }

    // Null for a round judged already or whose failed judging is recorded, so a redelivered message asks the model nothing.
    public function toJudge(string $roundId): ?ReviewRound
    {
        $round = $this->rounds->stored($roundId);

        return RoundState::Judged === $round->state() || $this->failedJudgings->isRecorded($roundId) ? null : $round;
    }

    /**
     * @return list<Finding>
     */
    public function findingsOf(ReviewRound $round): array
    {
        return $this->findings->reportedIn($round);
    }

    // Null for a reviewer whose verdict is not stored, so the caller can tell it from one that gave a verdict.
    /**
     * @return list<?Verdict>
     */
    public function verdictsOf(ReviewRound $round): array
    {
        return array_map($this->verdicts->ofReviewer(...), $round->reviewers());
    }

    public function changeOf(ReviewRound $round): string
    {
        return $this->change->of($round->diff(), $round->checkoutRoot());
    }

    // Checked again under the round's lock, because another worker may have judged the round while this one waited for the model, or recorded its judging as failed for good and queued its publication already, before a restart handed the judging out again; false then, so the caller announces only a judge transition that committed.
    public function record(ReviewRound $round, JudgedFindings $judged): bool
    {
        $recorded = false;
        $this->rounds->withLockedRound($round->id(), function (ReviewRound $locked) use ($judged, &$recorded): void {
            $this->findings->lockChangeOf($locked);

            if (!$this->failedJudgings->isRecorded($locked->id()->toRfc4122()) && $this->findings->markJudged($locked)) {
                $this->ledger->judgeDone($locked, $judged, $this->votesOf($locked, $judged));
                $this->recurringP1s->of($locked);
                $recorded = true;
            }
        });

        return $recorded;
    }

    // Counted once, with the judgement, and recorded with it, so every reader of the round counts the same votes.
    /**
     * @return array<string, Decision>
     */
    private function votesOf(ReviewRound $round, JudgedFindings $judged): array
    {
        $shown = array_map(static fn (Finding $finding): string => $finding->id, $this->findings->openFor($round));
        $counted = new CountedVote($this->findings->reportedIn($round), $shown, $judged);
        $votes = [];

        foreach ($round->reviewers() as $reviewer) {
            $verdict = $this->verdicts->ofReviewer($reviewer);

            if ($verdict instanceof Verdict) {
                $votes[$reviewer->id()->toRfc4122()] = $counted->of($verdict);
            }
        }

        return $votes;
    }
}
