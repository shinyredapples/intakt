<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\UseCase\JudgeRound\FailedJudgings;
use Intakt\Core\Review\UseCase\ReviewChange\UpperWait;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

// Touches only reviewer_conversation, never the ledger, because retention applies to what a model was shown and said, not to the record of what happened.
final readonly class StoredConversations
{
    // The time part of a version 7 id in its text form: eight hex digits, a hyphen and four more, so text order is start order.
    private const int TIME_PREFIX_LENGTH = 13;

    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entities,
        private FailedJudgings $failedJudgings,
        private ClockInterface $clock,
        private UpperWait $upperWait,
    ) {
    }

    // The store stamps updated_at at every save, so it is the last message's time. A conversation started at or after the oldest open round began is kept whatever its age, because a retry gives a reviewer a new conversation id and the round keeps only the latest, so an earlier attempt of an open round is known only by its start time.
    public function deleteLastChangedBefore(DateTimeImmutable $limit): int
    {
        $oldestOpen = $this->oldestOpenRoundStart();

        if (null === $oldestOpen) {
            return (int) $this->connection->executeStatement('DELETE FROM reviewer_conversation WHERE updated_at < ?', [$limit->getTimestamp()]);
        }

        return (int) $this->connection->executeStatement('DELETE FROM reviewer_conversation WHERE updated_at < ? AND id < ?', [$limit->getTimestamp(), $oldestOpen]);
    }

    // A round is open while it can still run: collecting, or complete and waiting for its judging, until its no-outcome deadline, the point where the review gives up on it too. The deadline is what keeps one stuck round from holding every later conversation for good. One whose failed judging the ledger records counts as finished at once. Only ids and reviewer counts are selected, because a loaded round would keep its whole diff in the entity manager.
    private function oldestOpenRoundStart(): ?string
    {
        /** @var list<array{id: string, reviewers: int|string}> $unjudged */
        $unjudged = $this->entities->createQueryBuilder()
            ->select('round.id AS id', 'COUNT(reviewer.id) AS reviewers')
            ->from(ReviewRound::class, 'round')
            ->leftJoin('round.reviewers', 'reviewer')
            ->where('round.state != :judged')
            ->groupBy('round.id')
            ->setParameter('judged', RoundState::Judged)
            ->getQuery()
            ->getScalarResult();

        $failed = $this->failedJudgings->recordedTimes();
        $starts = [];

        foreach ($unjudged as ['id' => $stored, 'reviewers' => $reviewers]) {
            $id = Uuid::fromString($stored);

            if (!isset($failed[$id->toRfc4122()]) && $this->isBeforeItsDeadline($id, (int) $reviewers)) {
                $starts[] = $this->startOf($id);
            }
        }

        return [] === $starts ? null : min($starts);
    }

    // A round id that carries no time has no deadline either, so it stays open.
    private function isBeforeItsDeadline(Uuid $round, int $reviewers): bool
    {
        if (!$round instanceof UuidV7) {
            return true;
        }

        $seconds = $this->upperWait->secondsForReviewers($reviewers);

        return $this->clock->now() < $round->getDateTime()->modify('+'.$seconds.' seconds');
    }

    // A round id that carries no time keeps every conversation, because no start can be told apart from its own.
    private function startOf(Uuid $round): string
    {
        return $round instanceof UuidV7 ? substr($round->toRfc4122(), 0, self::TIME_PREFIX_LENGTH) : '';
    }
}
