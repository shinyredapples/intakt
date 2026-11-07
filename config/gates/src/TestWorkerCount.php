<?php

declare(strict_types=1);

namespace Intakt\Gates;

final class TestWorkerCount
{
    public const string VARIABLE = 'INTAKT_TEST_WORKERS';

    private const int MOST_BY_DEFAULT = 4;

    public static function chosen(?string $override, string $processStatus, string $cpuMax): ?int
    {
        if (null !== $override) {
            return 1 === preg_match('/^[1-9]\d{0,2}$/', $override) && (int) $override <= self::most() ? (int) $override : null;
        }

        return min(self::processorsIn($processStatus), self::quotaIn($cpuMax), self::MOST_BY_DEFAULT);
    }

    // Because each worker needs a block of the default ports, a count beyond them would fail on ports the user never set.
    public static function most(): int
    {
        return TestPorts::mostWorkersByDefault();
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

    // Because a CPU quota (cgroup v2 `cpu.max`, `<quota> <period>`) caps a container below its affinity list; `max` or anything unreadable sets no cap.
    private static function quotaIn(string $cpuMax): int
    {
        if (1 !== preg_match('/^([1-9]\d*) ([1-9]\d*)$/', trim($cpuMax), $quota)) {
            return PHP_INT_MAX;
        }

        return max(1, (int) ceil((int) $quota[1] / (int) $quota[2]));
    }
}
