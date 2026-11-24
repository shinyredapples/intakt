<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\UseCase\JudgeRound\FailedJudgings;
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

    // A round is open while it can still run: collecting, or complete and waiting for its judging. One whose failed judging the ledger records stays complete for good, so it counts as finished; a judging parked in the failure transport alone does not, because it can be retried.
    private function oldestOpenRoundStart(): ?string
    {
        /** @var list<string|Uuid> $ids */
        $ids = $this->entities->createQueryBuilder()
            ->select('round.id')
            ->from(ReviewRound::class, 'round')
            ->where('round.state != :judged')
            ->setParameter('judged', RoundState::Judged)
            ->getQuery()
            ->getSingleColumnResult();

        $failed = $this->failedJudgings->recordedTimes();
        $starts = [];

        foreach ($ids as $id) {
            $round = $this->uuidOf($id);

            if (!isset($failed[$round->toRfc4122()])) {
                $starts[] = $this->startOf($round);
            }
        }

        return [] === $starts ? null : min($starts);
    }

    // Read in both forms because a scalar select returns the id as the platform stores it, binary on SQLite and MySQL and text on PostgreSQL.
    private function uuidOf(string|Uuid $id): Uuid
    {
        if ($id instanceof Uuid) {
            return $id;
        }

        return Uuid::isValid($id) ? Uuid::fromString($id) : Uuid::fromBinary($id);
    }

    // A round id that carries no time keeps every conversation, because no start can be told apart from its own.
    private function startOf(Uuid $round): string
    {
        return $round instanceof UuidV7 ? substr($round->toRfc4122(), 0, self::TIME_PREFIX_LENGTH) : '';
    }
}
