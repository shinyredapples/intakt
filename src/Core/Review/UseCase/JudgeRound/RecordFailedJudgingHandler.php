<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\UseCase\PublishVerdict\PublishVerdict;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

// Under the round's lock and in one transaction with the Doctrine queue, so the record and the queued publication commit together, and a judging that fails for good again after a worker restarted records and publishes nothing more.
#[AsCommandHandler]
final readonly class RecordFailedJudgingHandler
{
    public function __construct(
        private ReviewRounds $rounds,
        private RoundLedger $ledger,
        private FailedJudgings $failedJudgings,
        private MessageBusInterface $commands,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RecordFailedJudging $command): void
    {
        $this->rounds->withLockedRound(Uuid::fromString($command->roundId), function (ReviewRound $round): void {
            if (!$this->isStillUnjudged($round)) {
                return;
            }

            $this->ledger->judgingFailed($round);
            $this->commands->dispatch(new PublishVerdict($round->id()->toRfc4122()));
            $this->logger->error('The judging of round {round} failed for good; the round stays unjudged and is published as not reviewed.', ['round' => $round->id()->toRfc4122()]);
        });
    }

    // A round another worker judged meanwhile keeps its verdict, and one already recorded as failed was published already.
    private function isStillUnjudged(ReviewRound $round): bool
    {
        return RoundState::Complete === $round->state() && !$this->failedJudgings->hasFailed($round->id()->toRfc4122());
    }
}
