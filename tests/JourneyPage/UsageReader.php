<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

// Only the five named fields are accepted, so that no limit, reset time or quota can pass through.
final readonly class UsageReader
{
    private const array FIELDS = ['day', 'vendor', 'account', 'lane', 'tokens'];

    public function tokensIn(string $file): int
    {
        $text = is_file($file) ? file_get_contents($file) : false;

        if (false === $text) {
            throw new JourneyInputFailed('cannot read '.$file);
        }

        $lines = array_filter(explode("\n", $text), static fn (string $line): bool => '' !== trim($line));

        return array_sum(array_map($this->tokensOf(...), array_keys($lines), $lines));
    }

    private function tokensOf(int $index, string $line): int
    {
        $row = json_validate($line) ? json_decode($line, true) : null;

        $fields = is_array($row) ? array_keys($row) : [];
        sort($fields);
        $expected = self::FIELDS;
        sort($expected);

        if (!is_array($row) || $expected !== $fields) {
            throw $this->rejected($index, 'it must hold exactly '.implode(', ', self::FIELDS));
        }

        $tokens = $row['tokens'];

        if (!is_int($tokens) || $tokens < 0) {
            throw $this->rejected($index, 'tokens must be a whole number of zero or more');
        }

        return array_all(['day', 'vendor', 'account', 'lane'], static fn (string $field): bool => is_string($row[$field]) && '' !== $row[$field])
            ? $tokens
            : throw $this->rejected($index, 'day, vendor, account and lane must be text');
    }

    private function rejected(int $index, string $reason): JourneyInputFailed
    {
        return new JourneyInputFailed('usage line '.($index + 1).' is refused: '.$reason);
    }
}
