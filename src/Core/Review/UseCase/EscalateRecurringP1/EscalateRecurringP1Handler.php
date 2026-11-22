<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\EscalateRecurringP1;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Contract\EscalationFailed;
use Intakt\Core\Review\Contract\EscalationReviewer;
use Intakt\Core\Review\Contract\EscalationReviewFailed;
use Intakt\Core\Review\Contract\EscalationVerdict;
use Intakt\Core\Review\Contract\EscalationVerdictKept;
use Intakt\Core\Review\Contract\EscalationVerdictReused;
use Intakt\Core\Review\Domain\KeptVerdict;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\VerdictAlreadyKept;
use Intakt\Core\Review\Infrastructure\KeptVerdicts;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Symfony\Component\Lock\LockFactory;

// Queued from the judging's transaction and run after it, so the strong tier's call holds none of the judging's locks; a provider error from the call is not caught, so the queue tries the escalation again.
#[AsCommandHandler]
final readonly class EscalateRecurringP1Handler
{
    private const string LOCK_PREFIX = 'review-escalation-';

    public function __construct(
        private ReviewRounds $rounds,
        private EscalationRequests $requests,
        private KeptVerdicts $verdicts,
        private EscalationReviewer $reviewer,
        private RoundLedger $ledger,
        private EscalationsOfChange $recorded,
        private LockFactory $locks,
    ) {
    }

    // Not waited for, because Messenger brings a busy message back and it then finds the verdict the holder of the key kept, so two deliveries of one key rarely pay for two answers.
    public function __invoke(EscalateRecurringP1 $command): void
    {
        $lock = $this->locks->createLock(self::LOCK_PREFIX.hash('sha256', $command->key));

        if (!$lock->acquire()) {
            throw EscalationBusy::of($command->key);
        }

        try {
            $this->settle($command);
        } finally {
            $lock->release();
        }
    }

    // Once per round and key: what the ledger holds for them stands, so a redelivery asks no model and records nothing twice, also after a failure.
    private function settle(EscalateRecurringP1 $command): void
    {
        if (null !== $this->recorded->recordedFor($command->round, $command->key)) {
            return;
        }

        $kept = $this->verdicts->ofKey($command->key);

        if ($kept instanceof KeptVerdict) {
            $this->reuse($command, $kept);

            return;
        }

        $this->review($command);
    }

    private function review(EscalateRecurringP1 $command): void
    {
        $round = $this->rounds->stored($command->round);
        $request = $this->requests->of($round, $command->finding);

        if (is_string($request)) {
            $this->failed($command, $request);

            return;
        }

        $result = $this->reviewer->review($round->project(), $round->id(), $request);

        if ($result instanceof EscalationFailed) {
            $this->failed($command, $result->reason);

            return;
        }

        $this->keep($round, $command, $result->verdict);
    }

    private function failed(EscalateRecurringP1 $command, string $reason): void
    {
        $this->ledger->escalationFailed(new EscalationReviewFailed($command->round, $command->finding, $command->key, $reason));
    }

    // Under the round's lock and in one transaction, so a refused event takes the kept verdict back; another writer that kept the key first wins, and this round reuses its verdict.
    private function keep(ReviewRound $round, EscalateRecurringP1 $command, EscalationVerdict $verdict): void
    {
        $this->rounds->withLockedRound($round->id(), function () use ($command, $verdict): void {
            try {
                $this->verdicts->keep($command->key, $verdict, $command->round);
            } catch (VerdictAlreadyKept) {
                $winner = $this->verdicts->ofKey($command->key);
                assert($winner instanceof KeptVerdict);
                $this->reuse($command, $winner);

                return;
            }

            $this->ledger->escalationReviewed(new EscalationVerdictKept($command->round, $command->finding, $command->key, $verdict->outcome, $verdict->reasons));
        });
    }

    private function reuse(EscalateRecurringP1 $command, KeptVerdict $kept): void
    {
        $this->ledger->escalationVerdictReused(new EscalationVerdictReused($command->round, $command->finding, $command->key, $kept->outcome, $kept->round));
    }
}
