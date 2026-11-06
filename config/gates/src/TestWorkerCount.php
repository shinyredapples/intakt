<?php

declare(strict_types=1);

namespace Intakt\Gates;

final class TestWorkerCount
{
    public const string VARIABLE = 'INTAKT_TEST_WORKERS';

    // Because the test files wait on the processes they start (instance boots, servers, gitleaks) for much of their time, so one worker per processor leaves it idle.
    private const int WORKERS_PER_PROCESSOR = 2;
    private const int MOST_BY_DEFAULT = 8;

    public static function chosen(?string $override, string $processStatus): ?int
    {
        if (null !== $override) {
            return 1 === preg_match('/^[1-9]\d{0,2}$/', $override) && (int) $override <= self::most() ? (int) $override : null;
        }

        $processors = self::processorsIn($processStatus);

        return null === $processors ? 1 : min(self::WORKERS_PER_PROCESSOR * $processors, self::MOST_BY_DEFAULT);
    }

    // Because each worker needs a block of the default ports, a count beyond them would fail on ports the user never set.
    public static function most(): int
    {
        return TestPorts::mostWorkersByDefault();
    }

    // Because the processors the gate may run on (its affinity list) are what it sees, not every processor of the machine.
    private static function processorsIn(string $processStatus): ?int
    {
        if (1 !== preg_match('/^Cpus_allowed_list:\s*([\d,-]+)\s*$/m', $processStatus, $list)) {
            return null;
        }

        $processors = 0;

        foreach (explode(',', $list[1]) as $range) {
            $bounds = explode('-', $range);
            $processors += (int) array_last($bounds) - (int) $bounds[0] + 1;
        }

        return max(1, $processors);
    }
}
