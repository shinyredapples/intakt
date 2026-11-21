<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\EscalateRecurringP1;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Core\Review\Contract\ChangeUnderReview;
use Intakt\Core\Review\Contract\EscalationFailed;
use Intakt\Core\Review\Contract\EscalationRequest;
use Intakt\Core\Review\Contract\EscalationReviewed;
use Intakt\Core\Review\Contract\EscalationVerdict;
use Intakt\Core\Review\Contract\EscalationVerdictReused;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Domain\KeptVerdict;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\VerdictAlreadyKept;
use Intakt\Core\Review\Infrastructure\KeptVerdicts;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Intakt\Plugin\Vendor\Contract\EscalationReviews;
use Intakt\Plugin\Vendor\Contract\Vendor;

// Queued from the judging's transaction and run after it, so the strong tier's call holds none of the judging's locks; the kept verdict and its event commit together.
#[AsCommandHandler]
final readonly class EscalateRecurringP1Handler
{
    public const string UNREADABLE = 'the answer could not be read as an escalation verdict';
    public const string FINDING_GONE = 'the finding is not among the findings the round reported';

    public function __construct(
        private ReviewRounds $rounds,
        private StoredFindings $findings,
        private KeptVerdicts $verdicts,
        private Vendor $vendor,
        private EscalationReviews $escalations,
        private RoundLedger $ledger,
        private Redactor $redactor,
    ) {
    }

    public function __invoke(EscalateRecurringP1 $command): void
    {
        $kept = $this->verdicts->ofKey($command->key);

        // Asked already, by another round of the key or by this message before a redelivery, so the model is never asked for a second verdict.
        if ($kept instanceof KeptVerdict) {
            $this->reuse($command, $kept);

            return;
        }

        $round = $this->rounds->stored($command->round);
        $finding = $this->findingOf($round, $command->finding);

        if (!$finding instanceof Finding) {
            $this->ledger->escalationFailed(new EscalationFailed($command->round, $command->finding, $command->key, self::FINDING_GONE));

            return;
        }

        if (!$this->vendor->mapsAModelTo($round->project(), EscalationReviews::TIER)) {
            $this->ledger->escalationFailed(new EscalationFailed($command->round, $command->finding, $command->key, $this->unmappedIn($round->project())));

            return;
        }

        $verdict = $this->escalations->escalate($round->project(), new EscalationRequest(new ChangeUnderReview($round->project(), $round->task(), $round->diff()), $finding, $round->id()));

        if (!$verdict instanceof EscalationVerdict) {
            $this->ledger->escalationFailed(new EscalationFailed($command->round, $command->finding, $command->key, self::UNREADABLE));

            return;
        }

        $this->keep($round, $command, $this->redacted($verdict));
    }

    private function findingOf(ReviewRound $round, string $id): ?Finding
    {
        return array_find($this->findings->reportedIn($round), static fn (Finding $finding): bool => $finding->id === $id);
    }

    // Under the round's lock and in one transaction, so a refused event takes the kept verdict back; another writer that kept the key first wins, and this round reuses its verdict.
    private function keep(ReviewRound $round, EscalateRecurringP1 $command, EscalationVerdict $verdict): void
    {
        $this->rounds->withLockedRound($round->id(), function () use ($command, $verdict): void {
            try {
                $this->verdicts->keep($command->key, $verdict, $command->round);
            } catch (VerdictAlreadyKept) {
                $kept = $this->verdicts->ofKey($command->key);
                assert($kept instanceof KeptVerdict);
                $this->reuse($command, $kept);

                return;
            }

            $this->ledger->escalationReviewed(new EscalationReviewed($command->round, $command->finding, $command->key, $verdict->outcome, $verdict->reasons));
        });
    }

    // Nothing for this round's own verdict, because that is a redelivery whose escalation_reviewed is in the ledger already.
    private function reuse(EscalateRecurringP1 $command, KeptVerdict $kept): void
    {
        if ($kept->round === $command->round) {
            return;
        }

        $this->ledger->escalationVerdictReused(new EscalationVerdictReused($command->round, $command->finding, $command->key, $kept->outcome, $kept->round));
    }

    // Redacted before it is kept, because the reasons are model text that may quote a held credential and they reach the ledger, the next build and a reviewer.
    private function redacted(EscalationVerdict $verdict): EscalationVerdict
    {
        return new EscalationVerdict($verdict->outcome, trim($this->redactor->redact($verdict->reasons)));
    }

    private function unmappedIn(string $project): string
    {
        return sprintf('project "%s" maps no model to the "%s" tier, which the escalation review needs', $project, EscalationReviews::TIER->value);
    }
}
