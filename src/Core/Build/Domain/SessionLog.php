<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

use Generator;

// Runs in the reader's own process only, because build.log is the builder's to write; it reads the CLI's stream (--output-format stream-json), which writes each message as the session runs, so a cut session still counts what it spent (docs/design/05-architecture/06-budget-and-model-planner.md).
final readonly class SessionLog
{
    public const int MAX_LINE_BYTES = 1024 * 1024;

    // Deep enough for the CLI's records, so a line nested deeper is skipped and costs the decoder nothing more.
    private const int DEPTH = 16;

    private const string TYPE = 'type';

    private const string MESSAGE = 'message';

    public static function usageIn(string $log): SessionUsage
    {
        $messages = [];

        foreach (self::linesOf($log) as $line) {
            if (null === $line) {
                return SessionUsage::withoutUsage(UsageReading::LineTooLong);
            }

            $message = self::assistantMessageIn($line);

            if (null === $message) {
                continue;
            }

            $counted = self::countedMessage($message);

            if (null === $counted) {
                return SessionUsage::withoutUsage(UsageReading::Malformed);
            }

            // Keyed by the message id, because the CLI writes one record per content block of the same message.
            $messages[$counted[0]] = $counted[1];
        }

        return self::totalOf($messages);
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

    // Other lines are skipped, because the log also holds the CLI's other records, its errors and the script's own lines; the final result is one of them, since its usage repeats the messages'.
    /**
     * @return array<mixed>|null
     */
    private static function assistantMessageIn(string $line): ?array
    {
        $record = json_decode($line, true, self::DEPTH);

        if (!is_array($record) || 'assistant' !== ($record[self::TYPE] ?? null)) {
            return null;
        }

        return is_array($record[self::MESSAGE] ?? null) ? $record[self::MESSAGE] : [];
    }

    // Null for a record without an id or without valid counts, because it would otherwise settle the build as having spent less than it did.
    /**
     * @param array<mixed> $message
     *
     * @return array{string, BuildUsage}|null
     */
    private static function countedMessage(array $message): ?array
    {
        $id = $message['id'] ?? null;
        $usage = is_array($message['usage'] ?? null) ? BuildUsage::fromCounts($message['usage']) : null;

        return is_string($id) && '' !== $id && $usage instanceof BuildUsage ? [$id, $usage] : null;
    }

    /**
     * @param array<string, BuildUsage> $messages
     */
    private static function totalOf(array $messages): SessionUsage
    {
        $total = array_first($messages);

        if (null === $total) {
            return SessionUsage::withoutUsage(UsageReading::NotReported);
        }

        foreach (array_slice($messages, 1) as $usage) {
            $total = $total->plus($usage);

            if (null === $total) {
                return SessionUsage::withoutUsage(UsageReading::Malformed);
            }
        }

        return SessionUsage::read($total);
    }
}
