<?php

declare(strict_types=1);

namespace Intakt\Core\Bench\Domain;

use Intakt\Core\Review\Contract\Severity;
use JsonException;
use stdClass;

final readonly class Expectations
{
    /**
     * @param list<PlantedDefect> $defects
     */
    public function __construct(public array $defects)
    {
    }

    public static function fromJson(string $json): self
    {
        try {
            // Objects stay objects, so `{}` is refused instead of reading as an empty list.
            $entries = json_decode($json, false, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw InvalidExpectations::because($e->getMessage());
        }

        if (!is_array($entries)) {
            throw InvalidExpectations::because('the top level must be a list of defects');
        }

        $defects = array_map(self::defect(...), $entries);
        $ids = array_map(static fn (PlantedDefect $defect): string => $defect->id, $defects);
        $repeated = array_keys(array_filter(array_count_values($ids), static fn (int $count): bool => $count > 1));
        if ([] !== $repeated) {
            throw InvalidExpectations::because('the defect id '.implode(', ', $repeated).' is listed more than once');
        }

        return new self($defects);
    }

    public function isDefectFree(): bool
    {
        return [] === $this->defects;
    }

    private static function defect(mixed $entry): PlantedDefect
    {
        if (!$entry instanceof stdClass) {
            throw InvalidExpectations::because('a defect must be an object');
        }

        $entry = get_object_vars($entry);
        $id = self::text($entry, 'id');
        $lines = $entry['lines'] ?? null;
        $from = $lines instanceof stdClass ? ($lines->from ?? null) : null;
        $to = $lines instanceof stdClass ? ($lines->to ?? null) : null;
        if (!is_int($from) || !is_int($to) || $from < 1 || $to < $from) {
            throw InvalidExpectations::because('the lines of '.$id.' must be integers with 1 <= from <= to');
        }

        $severity = Severity::tryFrom(self::text($entry, 'severity'));
        if (!$severity instanceof Severity) {
            throw InvalidExpectations::because('the severity of '.$id.' must be P1, P2 or P3');
        }

        return new PlantedDefect($id, self::text($entry, 'file'), $from, $to, self::text($entry, 'description'), $severity);
    }

    /**
     * @param array<array-key, mixed> $entry
     */
    private static function text(array $entry, string $key): string
    {
        $value = $entry[$key] ?? null;
        if (!is_string($value) || '' === $value) {
            throw InvalidExpectations::because('"'.$key.'" must be a non-empty string');
        }

        return $value;
    }
}
