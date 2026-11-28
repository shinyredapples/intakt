<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

use Generator;

// Runs in the reader's own process only, because build.log is the builder's to write; it reads the CLI's JSON result (--output-format json), whose usage covers every turn of the session.
final readonly class SessionLog
{
    public const int MAX_LINE_BYTES = 1024 * 1024;

    // Deep enough for the CLI's result, so a line nested deeper is no result and costs the decoder nothing more.
    private const int DEPTH = 16;

    private const string TYPE = 'type';

    public static function usageIn(string $log): SessionUsage
    {
        $result = null;

        foreach (self::linesOf($log) as $line) {
            if (null === $line) {
                return SessionUsage::withoutUsage(UsageReading::LineTooLong);
            }
            $result = self::resultIn($line) ?? $result;
        }

        return null === $result ? SessionUsage::withoutUsage(UsageReading::NotReported) : self::usageOf($result);
    }

    // One line at a time and null for one over the limit, because a log of millions of short lines split at once would hold more memory than the reader may use.
    /**
     * @return Generator<int, ?string>
     */
    private static function linesOf(string $log): Generator
    {
        $length = strlen($log);

        for ($start = 0; $start <= $length; $start = $end + 1) {
            $found = strpos($log, "\n", $start);
            $end = false === $found ? $length : $found;

            yield $end - $start > self::MAX_LINE_BYTES ? null : substr($log, $start, $end - $start);
        }
    }

    // Other lines are skipped, because the log also holds the CLI's errors and the script's own lines.
    /**
     * @return array<mixed>|null
     */
    private static function resultIn(string $line): ?array
    {
        $event = json_decode($line, true, self::DEPTH);

        return is_array($event) && 'result' === ($event[self::TYPE] ?? null) ? $event : null;
    }

    // The last result only, because the CLI writes one when the session ends; a result without valid counts is malformed, never zero.
    /**
     * @param array<mixed> $result
     */
    private static function usageOf(array $result): SessionUsage
    {
        $usage = is_array($result['usage'] ?? null) ? BuildUsage::fromCounts($result['usage']) : null;

        return $usage instanceof BuildUsage ? SessionUsage::read($usage) : SessionUsage::withoutUsage(UsageReading::Malformed);
    }
}
