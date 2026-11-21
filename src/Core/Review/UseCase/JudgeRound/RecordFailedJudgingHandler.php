<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\UseCase\PublishVerdict\PublishVerdict;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

// Under the round's lock, then the change's as a judging takes them, and in one transaction with the Doctrine queue, so the record and the queued publication commit together, and a judging that fails for good again after a worker restarted records and publishes nothing more.
#[AsCommandHandler]
final readonly class RecordFailedJudgingHandler
{
    public function __construct(
        private ReviewRounds $rounds,
        private RoundLedger $ledger,
        private FailedJudgings $failedJudgings,
        private StoredFindings $findings,
        private RecordRecurringP1s $recurringP1s,
        private MessageBusInterface $commands,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RecordFailedJudging $command): void
    {
        $recorded = false;

        $this->rounds->withLockedRound(Uuid::fromString($command->roundId), function (ReviewRound $round) use (&$recorded): void {
            $this->findings->lockChangeOf($round);

            if (!$this->isStillUnjudged($round)) {
                return;
            }

            $this->ledger->judgingFailed($round);
            // Recorded with the failure, because the failed round no longer waits and the later rounds it held back have no other judging that would decide them.
            $this->recurringP1s->of($round);
            $this->commands->dispatch(new PublishVerdict($round->id()->toRfc4122()));
            $recorded = true;
        });

        // After the commit, so a log that cannot be written does not take the record and the queued publication back.
        if ($recorded) {
            $this->logger->error('The judging of round {round} failed for good; the round stays unjudged and is published as not reviewed.', ['round' => $command->roundId]);
        }
    }

    // A round another worker judged meanwhile keeps its verdict, and one whose failure is recorded was published already; a parked copy of the judging says neither, so it does not count here.
    private function isStillUnjudged(ReviewRound $round): bool
    {
        return RoundState::Complete === $round->state() && !$this->failedJudgings->isRecorded($round->id()->toRfc4122());
    }
}
