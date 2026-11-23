<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

// Only the lines with the run's mark up to the first mode line count, because the script logs them before it runs the job with exec and drops the mark first, so the job can neither change them nor forge one, even after log rotation dropped them.
final readonly class StartCheckLog
{
    // Only the head of the log is read, because the check's lines come first and the job could flood its standard error after them.
    public const int MAX_BYTES = 64 * 1024;

    // At most this many failures reach the ledger, so a job cannot flood it.
    public const int MAX_FAILURES = 20;

    private const int MAX_REASON_BYTES = 255;
    private const string FULL_MODE = 'full mode';
    private const string REDUCED_MODE = 'reduced mode: ';

    /**
     * @return list<StartCheckFailure>
     */
    public function failuresIn(string $standardError, string $mark): array
    {
        $lines = $this->checkLinesOf($standardError, $this->prefixOf($mark));
        $modeAt = array_find_key($lines, $this->isModeLine(...));
        $before = array_filter($lines, static fn (int $index): bool => null === $modeAt || $index < $modeAt, ARRAY_FILTER_USE_KEY);
        $failures = array_values(array_filter(array_map($this->failureOn(...), $before, array_keys($before))));

        return array_slice([...$failures, ...$this->modeFailures(null === $modeAt ? null : $lines[$modeAt], $failures)], 0, self::MAX_FAILURES);
    }

    private function prefixOf(string $mark): string
    {
        return sprintf('intakt-start-check %s: ', $mark);
    }

    /**
     * @return array<int, string>
     */
    // Keyed by the line's index in the log, so a failure can name it; a last part without its line end is left out, because the read limit may have cut it.
    private function checkLinesOf(string $standardError, string $prefix): array
    {
        $lines = array_slice(explode("\n", $standardError), 0, -1);
        $checkLines = array_filter($lines, static fn (string $line): bool => str_starts_with($line, $prefix));

        return array_map(static fn (string $line): string => substr($line, strlen($prefix)), $checkLines);
    }

    private function isModeLine(string $line): bool
    {
        return self::FULL_MODE === $line || str_starts_with($line, self::REDUCED_MODE);
    }

    // Valid UTF-8 without control characters only, so an input path in any language passes and a reason can always be stored as JSON.
    private function failureOn(string $line, int $index): ?StartCheckFailure
    {
        $checks = implode('|', array_map(static fn (StartCheck $check): string => $check->value, StartCheck::logged()));

        if (1 !== preg_match('/^('.$checks.'): ([^\p{Cc}]+)$/Du', $line, $match)) {
            return new StartCheckFailure(StartCheck::Log, sprintf('line %d of the log cannot be read', $index + 1));
        }

        if ('ok' === $match[2] || str_ends_with($match[2], ': ok')) {
            return null;
        }

        return new StartCheckFailure(StartCheck::from($match[1]), mb_strcut($match[2], 0, self::MAX_REASON_BYTES, 'UTF-8'));
    }

    /**
     * @param list<StartCheckFailure> $failures
     *
     * @return list<StartCheckFailure>
     */
    // A log without a mode line never passes, and a disagreeing mode line fails too, because the script logs it from the same failures, so the log was not the script's alone.
    private function modeFailures(?string $modeLine, array $failures): array
    {
        if (null === $modeLine) {
            return [new StartCheckFailure(StartCheck::Log, sprintf('no mode line in the first %d bytes of the log: the check did not finish, did not run, or its lines were rotated away', self::MAX_BYTES))];
        }

        $failed = array_filter($failures, static fn (StartCheckFailure $failure): bool => StartCheck::Log !== $failure->check);
        $named = array_values(array_unique(array_map(static fn (StartCheckFailure $failure): string => $failure->check->value, $failed)));
        $expected = [] === $named ? self::FULL_MODE : self::REDUCED_MODE.implode(' ', $named);

        return $expected === $modeLine ? [] : [new StartCheckFailure(StartCheck::Log, 'the mode line does not name the checks that failed before it')];
    }
}
