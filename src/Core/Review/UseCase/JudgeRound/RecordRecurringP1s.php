<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Review\Contract\EscalationVerdictReused;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\RecurringP1Found;
use Intakt\Core\Review\Domain\KeptVerdict;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Infrastructure\KeptVerdicts;
use Intakt\Core\Review\Infrastructure\SettledCode;
use Intakt\Core\Review\UseCase\EscalateRecurringP1\EscalateRecurringP1;
use Intakt\Core\Review\UseCase\EscalateRecurringP1\RecordFailedEscalation;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Symfony\Component\Messenger\MessageBusInterface;

// Called in the judging's transaction, under the round's and the change's locks, so a recurrence, the reuse of its kept verdict and its queued escalation review commit with the judge_done or the recorded failure that shows them.
final readonly class RecordRecurringP1s
{
    public function __construct(
        private RecurringP1s $recurringP1s,
        private KeptVerdicts $verdicts,
        private RoundLedger $ledger,
        private MessageBusInterface $commands,
        private StoredFindings $findings,
        private SettledCode $code,
    ) {
    }

    public function of(ReviewRound $round): void
    {
        foreach ($this->recurringP1s->foundWith($round) as $found) {
            $this->ledger->recurringP1Found($found);
            $this->reuseOrEscalate($round, $found);
        }
    }

    // Only on an exact key, because the key holds the text and every named file as it was reviewed, so a verdict is never reused on edited code; without a kept verdict the finding alone is queued for the escalation review, which runs after this transaction, because no model is asked under the judging's locks.
    private function reuseOrEscalate(ReviewRound $round, RecurringP1Found $found): void
    {
        $kept = $this->verdicts->ofKey($found->key);

        if ($kept instanceof KeptVerdict) {
            $this->ledger->escalationVerdictReused(new EscalationVerdictReused($found->round, $found->finding, $found->key, $kept->outcome, $kept->round));

            return;
        }

        $codeHash = $this->codeHashOf($round, $found);
        $this->escalate($found, $codeHash);
    }

    // Only for the round being judged, because only its checkout is sure to be there still; a recurrence found for an older round is escalated without one and its rejection is not settled.
    private function codeHashOf(ReviewRound $round, RecurringP1Found $found): ?string
    {
        if ($found->round !== $round->id()->toRfc4122()) {
            return null;
        }

        $file = array_find($this->findings->reportedIn($round), static fn (Finding $reported): bool => $reported->id === $found->finding)?->file;

        return null === $file ? null : $this->code->inRoot($round->checkoutRoot(), $file)?->hash;
    }

    // With the record of its failure as its failure stamp, so an escalation parked for good ends in escalation_failed and its change is no longer pending.
    private function escalate(RecurringP1Found $found, ?string $codeHash): void
    {
        $escalation = new EscalateRecurringP1($found->round, $found->finding, $found->key, $codeHash);

        $this->commands->dispatch($escalation, [RecordFailedEscalation::onFailureOf($escalation)]);
    }
}
