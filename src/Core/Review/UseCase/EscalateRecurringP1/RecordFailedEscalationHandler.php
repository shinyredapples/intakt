<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\EscalateRecurringP1;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Contract\EscalationReviewFailed;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Uid\Uuid;

// Under the key's lock the escalation takes, so a copy of the escalation still running records its own outcome first and this record then writes nothing; asked again under the round's lock, as the escalation asks, so a redelivered record writes no second failure.
#[AsCommandHandler]
final readonly class RecordFailedEscalationHandler
{
    public const string PARKED = 'the escalation review failed for good after its retries, so no verdict was kept';

    public function __construct(
        private ReviewRounds $rounds,
        private EscalationsOfChange $recorded,
        private RoundLedger $ledger,
        private LockFactory $locks,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RecordFailedEscalation $command): void
    {
        $lock = $this->locks->createLock(EscalateRecurringP1Handler::lockNameOf($command->key));

        if (!$lock->acquire()) {
            throw EscalationBusy::of($command->key);
        }

        try {
            $recorded = $this->recordedOnce($command);
        } finally {
            $lock->release();
        }

        // After the record, so a log that cannot be written does not take it back.
        if ($recorded) {
            $this->logger->error('The escalation review of finding {finding} in round {round} failed for good; no verdict was kept.', ['finding' => $command->finding, 'round' => $command->round]);
        }
    }

    private function recordedOnce(RecordFailedEscalation $command): bool
    {
        $recorded = false;

        $this->rounds->withLockedRound(Uuid::fromString($command->round), function () use ($command, &$recorded): void {
            if (null !== $this->recorded->recordedFor($command->round, $command->key)) {
                return;
            }

            $this->ledger->escalationFailed(new EscalationReviewFailed($command->round, $command->finding, $command->key, self::PARKED));
            $recorded = true;
        });

        return $recorded;
    }
}
