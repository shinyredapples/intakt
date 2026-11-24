<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\Review\Domain\RoundState;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

// Touches only reviewer_conversation, never the ledger, because retention applies to what a model was shown and said, not to the record of what happened.
final readonly class StoredConversations
{
    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entities,
    ) {
    }

    // A conversation of a round that is not judged yet is kept whatever its age, because its reviewers may still run; the store stamps updated_at at every save, so it is the last message's time.
    public function deleteLastChangedBefore(DateTimeImmutable $limit): int
    {
        $open = $this->openConversationIds();

        if ([] === $open) {
            return (int) $this->connection->executeStatement('DELETE FROM reviewer_conversation WHERE updated_at < ?', [$limit->getTimestamp()]);
        }

        return (int) $this->connection->executeStatement(
            'DELETE FROM reviewer_conversation WHERE updated_at < ? AND id NOT IN (?)',
            [$limit->getTimestamp(), $open],
            [1 => ArrayParameterType::STRING],
        );
    }

    // A scalar select returns the column as the platform stores it: binary on SQLite and MySQL, text on PostgreSQL.
    /**
     * @return list<string>
     */
    private function openConversationIds(): array
    {
        $ids = $this->entities->createQueryBuilder()
            ->select('r.conversationId')
            ->from(RoundReviewer::class, 'r')
            ->join('r.round', 'round')
            ->where('round.state != :judged')
            ->andWhere('r.conversationId IS NOT NULL')
            ->setParameter('judged', RoundState::Judged)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(static fn (string|UuidV7 $id): string => $id instanceof UuidV7 ? $id->toRfc4122() : (Uuid::isValid($id) ? $id : Uuid::fromBinary($id)->toRfc4122()), $ids));
    }
}
