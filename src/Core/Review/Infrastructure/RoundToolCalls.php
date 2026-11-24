<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Intakt\Core\Platform\Contract\Redaction;
use Intakt\Core\Review\Contract\ToolCallCount;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundToolCall;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;

// Recorded by the review server, because every reviewer's tool calls pass through it, also those of a subscription CLI whose conversation Intakt never stores.
final readonly class RoundToolCalls
{
    public function __construct(
        private EntityManagerInterface $entities,
        private LoggerInterface $logger,
        private Redaction $redaction,
    ) {
    }

    public function called(ReviewRound $round, string $tool): void
    {
        $this->record(new RoundToolCall($round, $tool, null));
    }

    public function read(ReviewRound $round, string $tool, string $path): void
    {
        $this->record(new RoundToolCall($round, $tool, $path));
    }

    /**
     * @return list<ToolCallCount>
     */
    public function countedIn(ReviewRound $round): array
    {
        /** @var list<array{tool: string, calls: int|string}> $rows */
        $rows = $this->rowsOf($round, 'SELECT c.tool, COUNT(c.position) AS calls FROM %s c WHERE c.round = :round GROUP BY c.tool ORDER BY c.tool');

        return array_map(static fn (array $row): ToolCallCount => new ToolCallCount($row['tool'], (int) $row['calls']), $rows);
    }

    /**
     * @return list<string>
     */
    public function filesReadIn(ReviewRound $round): array
    {
        /** @var list<array{fileRead: string}> $rows */
        $rows = $this->rowsOf($round, 'SELECT c.fileRead, MIN(c.position) AS HIDDEN firstRead FROM %s c WHERE c.round = :round AND c.fileRead IS NOT NULL GROUP BY c.fileRead ORDER BY firstRead');

        // Redacted on the way out, because the summary is published live and a path is the model's text; rows kept before this read the same.
        return array_map($this->redaction->redact(...), array_column($rows, 'fileRead'));
    }

    /**
     * @return array<mixed>
     */
    private function rowsOf(ReviewRound $round, string $dql): array
    {
        // Bound by the id's type, because the round entity as parameter is bound as text and misses the binary UUID SQLite stores.
        return $this->entities->createQuery(sprintf($dql, RoundToolCall::class))
            ->setParameter('round', $round->id(), UuidType::NAME)
            ->getArrayResult();
    }

    // Best effort, because the answer is already computed and the reviewer needs it more than the summary needs the row.
    private function record(RoundToolCall $call): void
    {
        try {
            $this->entities->persist($call);
            $this->entities->flush();
        } catch (Exception|ORMException $failure) {
            $this->logger->error('A tool call of the review server was not recorded; it is answered anyway.', ['round' => $call->round->id()->toRfc4122(), 'tool' => $call->tool, 'exception' => $failure]);
        }
    }
}
