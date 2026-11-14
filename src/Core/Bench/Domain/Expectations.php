<?php

declare(strict_types=1);

namespace Intakt\Core\Bench\Domain;

use Intakt\Core\Review\Contract\Severity;
use JsonException;

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
            $entries = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw InvalidExpectations::because($e->getMessage());
        }

        if (!is_array($entries) || !array_is_list($entries)) {
            throw InvalidExpectations::because('the top level must be a list of defects');
        }

        return new self(array_map(self::defect(...), $entries));
    }

    public function isDefectFree(): bool
    {
        return [] === $this->defects;
    }

    private static function defect(mixed $entry): PlantedDefect
    {
        if (!is_array($entry)) {
            throw InvalidExpectations::because('a defect must be an object');
        }

        $id = self::text($entry, 'id');
        $lines = $entry['lines'] ?? null;
        $from = is_array($lines) ? ($lines['from'] ?? null) : null;
        $to = is_array($lines) ? ($lines['to'] ?? null) : null;
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
