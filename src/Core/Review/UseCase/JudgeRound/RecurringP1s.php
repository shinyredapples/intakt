<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\JudgedFindings;
use Intakt\Core\Review\Contract\RecurringP1Found;
use Intakt\Core\Review\Domain\RecurringP1;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Infrastructure\FindingKeys;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;

// Called in the judging's transaction on the round's lock, so a redelivered judging, which finds the round judged, records no recurrence twice.
final readonly class RecurringP1s
{
    public function __construct(
        private ReviewRounds $rounds,
        private StoredFindings $findings,
        private RoundLedgerReading $reading,
        private FindingKeys $keys,
        private RoundLedger $ledger,
    ) {
    }

    public function recordFor(ReviewRound $round, JudgedFindings $judged): void
    {
        $earlier = $this->rounds->judgedBeforeOnAnotherHead($round);

        if (!$earlier instanceof ReviewRound) {
            return;
        }

        $current = $this->findings->actListOf($round, $this->idsOf($judged->actList), $judged->sameCause);

        foreach (new RecurringP1()->in($this->actListOf($earlier), $current) as [$finding, $before]) {
            $this->ledger->recurringP1Found($this->found($round, $earlier, $finding, $before));
        }
    }

    // The earlier round's act list as its judge_done recorded it, so a finding dropped before its judge never counts as flagged.
    /**
     * @return list<Finding>
     */
    private function actListOf(ReviewRound $earlier): array
    {
        $id = $earlier->id()->toRfc4122();

        return $this->findings->actListOf($earlier, $this->reading->actIdsOf($id), $this->reading->sameCauseOf($id));
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<string>
     */
    private function idsOf(array $findings): array
    {
        return array_map(static fn (Finding $finding): string => $finding->id, $findings);
    }

    // Keyed at this round's head, because that is the code an escalation of the finding would judge.
    private function found(ReviewRound $round, ReviewRound $earlier, Finding $finding, Finding $before): RecurringP1Found
    {
        return new RecurringP1Found(
            $round->id()->toRfc4122(),
            $round->number(),
            $finding->id,
            $earlier->id()->toRfc4122(),
            $earlier->number(),
            $before->id,
            (string) $finding->file,
            $finding->symbol,
            $this->keys->keyOf($finding, $round->checkoutRoot()),
        );
    }
}
