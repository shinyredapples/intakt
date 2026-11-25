<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\ConversionException;
use Intakt\Core\Build\Contract\ParkedQuestion;
use Intakt\Core\Build\Contract\ParkedQuestions;
use Intakt\Core\Build\Domain\ParkedTaskRecord;

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
            sprintf('SELECT question, choices, recommendation, parked_at FROM %s WHERE project = ? AND task = ?', ParkedTaskRecord::TABLE),
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
        try {
            $question = $this->converted($row['question'] ?? null, 'json');
            $choices = $this->converted($row['choices'] ?? null, 'json');
            $recommendation = $this->converted($row['recommendation'] ?? null, 'json');
            $parkedAt = $this->converted($row['parked_at'] ?? null, 'utc_datetime_immutable');
        } catch (ConversionException) {
            return null;
        }

        if (!is_string($question) || !is_string($recommendation) || !$parkedAt instanceof DateTimeImmutable || !$this->isListOfTexts($choices)) {
            return null;
        }

        return new ParkedQuestion($question, $choices, $recommendation, $parkedAt);
    }

    private function converted(mixed $value, string $type): mixed
    {
        return $this->connection->convertToPHPValue($value, $type);
    }

    /**
     * @phpstan-assert-if-true non-empty-list<string> $choices
     */
    private function isListOfTexts(mixed $choices): bool
    {
        return is_array($choices) && [] !== $choices && array_is_list($choices) && array_all($choices, static fn (mixed $choice): bool => is_string($choice));
    }
}
