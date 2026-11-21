<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Review\Contract\EscalationVerdictReused;
use Intakt\Core\Review\Contract\RecurringP1Found;
use Intakt\Core\Review\Domain\KeptVerdict;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Infrastructure\KeptVerdicts;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;

// Called in the judging's transaction, under the round's and the change's locks, so a recurrence and the reuse of its kept verdict commit with the judge_done or the recorded failure that shows them.
final readonly class RecordRecurringP1s
{
    public function __construct(
        private RecurringP1s $recurringP1s,
        private KeptVerdicts $verdicts,
        private RoundLedger $ledger,
    ) {
    }

    public function of(ReviewRound $round): void
    {
        foreach ($this->recurringP1s->foundWith($round) as $found) {
            $this->ledger->recurringP1Found($found);
            $this->reuseVerdictOf($found);
        }
    }

    // Only on an exact key, because the key holds the text and every named file as it was reviewed, so a verdict is never reused on edited code; nothing asks a model.
    private function reuseVerdictOf(RecurringP1Found $found): void
    {
        $kept = $this->verdicts->ofKey($found->key);

        if ($kept instanceof KeptVerdict) {
            $this->ledger->escalationVerdictReused(new EscalationVerdictReused($found->round, $found->finding, $found->key, $kept->outcome, $kept->round));
        }
    }
}
