<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use RuntimeException;

// A process number together with the moment its process started (clock ticks since boot, from /proc/<pid>/stat), because the system gives the number to another process once the first one ended, and only the pair names one process. Not the environment or the command line: a parallel worker sets its process title, which overwrites both.
final readonly class RecordedProcess
{
    public function __construct(
        public int $pid,
        public int $started,
    ) {
    }

    public static function current(): self
    {
        $pid = getmypid();

        if (false === $pid) {
            throw new RuntimeException('This process has no process number.');
        }

        return new self($pid, self::startOf($pid) ?? throw new RuntimeException('This process has no start time in /proc.'));
    }

    public function stillRuns(): bool
    {
        return self::startOf($this->pid) === $this->started;
    }

    private static function startOf(int $pid): ?int
    {
        $stat = @file_get_contents('/proc/'.$pid.'/stat');
        $name = false === $stat ? false : strrpos($stat, ')');

        if (false === $stat || false === $name) {
            return null;
        }

        // The fields after the process name, which may itself hold spaces and parentheses: the state is field 3, the start time field 22.
        $started = explode(' ', substr($stat, $name + 2))[19] ?? null;

        return is_numeric($started) ? (int) $started : null;
    }
}
