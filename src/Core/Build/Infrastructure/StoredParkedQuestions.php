<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\ConversionException;
use Intakt\Core\Build\Contract\ParkedQuestion;
use Intakt\Core\Build\Contract\ParkedQuestions;
use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\Domain\ParkedTaskRecord;
use Symfony\Component\Uid\Uuid;
use ValueError;

// Read on the connection, as the parking writes the row, so the row is read as stored and never through an entity another holder changed.
final readonly class StoredParkedQuestions implements ParkedQuestions
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function of(string $project, int $task): ?ParkedQuestion
    {
        $row = $this->connection->fetchAssociative(
            sprintf('SELECT question, choices, recommendation, parked_at, run FROM %s WHERE project = ? AND task = ?', ParkedTaskRecord::TABLE),
            [$project, $task],
            [ParameterType::STRING, ParameterType::INTEGER],
        );

        return false === $row ? null : $this->questionIn($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function questionIn(array $row): ?ParkedQuestion
    {
        $run = $row['run'] ?? null;

        // A row parked before rows named their run has none, so no reader can say which run it belongs to.
        if (!is_string($run) || !Uuid::isValid($run)) {
            return null;
        }

        try {
            $parkedAt = $this->connection->convertToPHPValue($row['parked_at'] ?? null, 'utc_datetime_immutable');
        } catch (ConversionException|ValueError) {
            // ValueError too, because PHP's date parser throws it for a text with a NUL character.
            return null;
        }

        $question = BuildQuestion::fromStored($this->decoded($row['question'] ?? null), $this->decoded($row['choices'] ?? null), $this->decoded($row['recommendation'] ?? null));

        if (!$question instanceof BuildQuestion || !$parkedAt instanceof DateTimeImmutable) {
            return null;
        }

        return new ParkedQuestion($question->question, $question->choices, $question->recommendation, $parkedAt, $run);
    }

    // Decoded to objects and not with the connection's json type, because an associative decoding turns {"0":"a"} into a list and so an object into choices.
    private function decoded(mixed $text): mixed
    {
        return is_string($text) ? json_decode($text, false, 8) : null;
    }
}
