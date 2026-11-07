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

        return min(Processors::available($processStatus, $cpuMax), self::MOST_BY_DEFAULT);
    }

    // Because each worker needs a block of the default ports, a count beyond them would fail on ports the user never set.
    public static function most(): int
    {
        return TestPorts::mostWorkersByDefault();
    }
}
