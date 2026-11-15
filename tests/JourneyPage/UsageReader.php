<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use BackedEnum;
use DateTimeImmutable;
use DateTimeZone;

// Only the five named fields are accepted, so that no limit, reset time or quota can pass through.
final readonly class UsageReader
{
    private const array FIELDS = ['day', 'vendor', 'account', 'lane', 'tokens'];

    public function usageIn(string $file): Usage
    {
        $text = is_file($file) ? file_get_contents($file) : false;

        if (false === $text) {
            throw new JourneyInputFailed('cannot read '.$file);
        }

        $lines = array_filter(explode("\n", $text), static fn (string $line): bool => '' !== trim($line));

        return new Usage(array_map($this->rowOf(...), array_keys($lines), $lines));
    }

    private function rowOf(int $index, string $line): TokenUsage
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

        if (!array_all(['day', 'vendor', 'account', 'lane'], static fn (string $field): bool => is_string($row[$field]) && '' !== $row[$field])) {
            throw $this->rejected($index, 'day, vendor, account and lane must be text');
        }

        return new TokenUsage(
            $this->day($index, $row['day']),
            UsageVendor::tryFrom($row['vendor']) ?? throw $this->unknown($index, 'vendor', UsageVendor::cases()),
            UsageAccount::tryFrom($row['account']) ?? throw $this->unknown($index, 'account', UsageAccount::cases()),
            UsageLane::tryFrom($row['lane']) ?? throw $this->unknown($index, 'lane', UsageLane::cases()),
            $tokens,
        );
    }

    private function day(int $index, string $text): DateTimeImmutable
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $text, new DateTimeZone('UTC'));

        return false !== $day && $day->format('Y-m-d') === $text ? $day : throw $this->rejected($index, 'day must be a date written YYYY-MM-DD');
    }

    /**
     * @param list<BackedEnum> $known
     */
    private function unknown(int $index, string $field, array $known): JourneyInputFailed
    {
        return $this->rejected($index, $field.' must be one of '.implode(', ', array_map(static fn (BackedEnum $case): string => (string) $case->value, $known)));
    }

    private function rejected(int $index, string $reason): JourneyInputFailed
    {
        return new JourneyInputFailed('usage line '.($index + 1).' is refused: '.$reason);
    }
}
