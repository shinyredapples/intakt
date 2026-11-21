<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\EscalateRecurringP1;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Core\Review\Contract\EscalationFailed;
use Intakt\Core\Review\Contract\EscalationOutcome;
use Intakt\Core\Review\Contract\EscalationRequest;
use Intakt\Core\Review\Contract\EscalationReviewed;
use Intakt\Core\Review\Contract\EscalationVerdict;
use Intakt\Core\Review\Contract\EscalationVerdictReused;
use Intakt\Core\Review\Domain\KeptVerdict;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\VerdictAlreadyKept;
use Intakt\Core\Review\Infrastructure\KeptVerdicts;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Plugin\Vendor\Contract\EscalationReviews;
use Intakt\Plugin\Vendor\Contract\ProviderFailedTransiently;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorAccountBusy;
use Symfony\Component\Lock\LockFactory;

// Queued from the judging's transaction and run after it, so the strong tier's call holds none of the judging's locks; the kept verdict and its event commit together.
#[AsCommandHandler]
final readonly class EscalateRecurringP1Handler
{
    public const string UNREADABLE = 'the answer could not be read as an escalation verdict';

    private const string LOCK_PREFIX = 'review-escalation-';

    public function __construct(
        private ReviewRounds $rounds,
        private EscalationRequests $requests,
        private KeptVerdicts $verdicts,
        private Vendor $vendor,
        private EscalationReviews $escalations,
        private RoundLedger $ledger,
        private EscalationsOfChange $recorded,
        private HolderReReviews $reReviews,
        private Redactor $redactor,
        private LockFactory $locks,
    ) {
    }

    public function __invoke(EscalateRecurringP1 $command): void
    {
        $outcome = $this->settledWhileTheKeyIsHeld($command);

        // Also on a redelivery and on a verdict reused at the judging, because the re-review is started only once per head and a round already started refuses a second start.
        if (EscalationOutcome::Rejected === $outcome) {
            $this->reReviews->startAfterRejectionIn($command->round);
        }
    }

    // Not waited for, because Messenger brings a busy message back and it then finds the verdict the holder of the key kept, so two deliveries never pay for two answers.
    private function settledWhileTheKeyIsHeld(EscalateRecurringP1 $command): ?EscalationOutcome
    {
        $lock = $this->locks->createLock(self::lockNameOf($command->key));

        if (!$lock->acquire()) {
            throw EscalationBusy::of($command->key);
        }

        try {
            return $this->settled($command);
        } finally {
            $lock->release();
        }
    }

    // Once per round and key: what the ledger holds for them stands, so a redelivery asks no model and records nothing twice; null for a failed escalation.
    private function settled(EscalateRecurringP1 $command): ?EscalationOutcome
    {
        $recorded = $this->recorded->recordedFor($command->round, $command->key);

        if (null !== $recorded) {
            return $recorded instanceof EscalationFailed ? null : $recorded->outcome;
        }

        $kept = $this->verdicts->ofKey($command->key);

        if ($kept instanceof KeptVerdict) {
            $this->reuse($command, $kept);

            return $kept->outcome;
        }

        return $this->reviewed($command);
    }

    private function reviewed(EscalateRecurringP1 $command): ?EscalationOutcome
    {
        $round = $this->rounds->stored($command->round);
        $request = $this->requests->of($round, $command->finding);

        if (is_string($request)) {
            return $this->failed($command, $request);
        }

        if (!$this->vendor->mapsAModelTo($round->project(), EscalationReviews::TIER)) {
            return $this->failed($command, $this->unmappedIn($round->project()));
        }

        $verdict = $this->answerOf($round->project(), $request);

        if (!$verdict instanceof EscalationVerdict) {
            return $this->failed($command, self::UNREADABLE);
        }

        return $this->keep($round, $command, $this->redacted($verdict));
    }

    // The same lock for the escalation and the record of its failure, so the two never write for one key at once.
    public static function lockNameOf(string $key): string
    {
        return self::LOCK_PREFIX.hash('sha256', $key);
    }

    private function answerOf(string $project, EscalationRequest $request): ?EscalationVerdict
    {
        try {
            return $this->escalations->escalate($project, $request);
        } catch (ProviderRateLimited $limited) {
            throw EscalationLimited::after($limited->retryAfterSeconds);
        } catch (VendorAccountBusy|ProviderFailedTransiently) {
            throw EscalationLimited::after(null);
        }
    }

    private function failed(EscalateRecurringP1 $command, string $reason): null
    {
        $this->ledger->escalationFailed(new EscalationFailed($command->round, $command->finding, $command->key, $reason));

        return null;
    }

    // Under the round's lock and in one transaction, so a refused event takes the kept verdict back; another writer that kept the key first wins, and this round reuses its verdict.
    private function keep(ReviewRound $round, EscalateRecurringP1 $command, EscalationVerdict $verdict): EscalationOutcome
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

        return $this->verdicts->ofKey($command->key)->outcome ?? $verdict->outcome;
    }

    private function reuse(EscalateRecurringP1 $command, KeptVerdict $kept): void
    {
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
