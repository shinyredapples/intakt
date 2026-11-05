<?php

declare(strict_types=1);

namespace Intakt\Gates;

final class TestWorkerCount
{
    public const string VARIABLE = 'INTAKT_TEST_WORKERS';

    private const int MOST_BY_DEFAULT = 4;

    public static function chosen(?string $override, string $processStatus): ?int
    {
        if (null !== $override) {
            return 1 === preg_match('/^[1-9]\d{0,2}$/', $override) ? (int) $override : null;
        }

        return min(self::processorsIn($processStatus), self::MOST_BY_DEFAULT);
    }

    // Because the processors the gate may run on (its affinity list) are what it sees, not every processor of the machine.
    private static function processorsIn(string $processStatus): int
    {
        if (1 !== preg_match('/^Cpus_allowed_list:\s*([\d,-]+)\s*$/m', $processStatus, $list)) {
            return 1;
        }

        $processors = 0;

        foreach (explode(',', $list[1]) as $range) {
            $bounds = explode('-', $range);
            $processors += (int) array_last($bounds) - (int) $bounds[0] + 1;
        }

        return max(1, $processors);
    }
}
