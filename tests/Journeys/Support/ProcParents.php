<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

// Reads a process's parent and start time from Linux's /proc, so a journey can tell a consumer's worker from a process that later got the same PID.
final readonly class ProcParents
{
    public function __construct(
        private string $proc = '/proc',
    ) {
    }

    public function parentOf(int $pid): ?int
    {
        return $this->entryOf($pid)?->parent;
    }

    // In clock ticks since boot, which no two processes holding one PID share, so the PID and this time together name one process.
    public function startOf(int $pid): ?int
    {
        return $this->entryOf($pid)?->start;
    }

    public function entryOf(int $pid): ?ProcessEntry
    {
        $fields = $this->fieldsOf($pid);
        $parent = $fields[1] ?? '';
        $start = $fields[19] ?? '';

        return ctype_digit($parent) && ctype_digit($start) ? new ProcessEntry((int) $parent, (int) $start) : null;
    }

    /**
     * @return list<string>
     */
    private function fieldsOf(int $pid): array
    {
        // Read without a check first and with its warning silenced, because a worker that ends between a check and the read is a process with no parent, not an error.
        $stat = @file_get_contents($this->proc.'/'.$pid.'/stat');
        $close = false === $stat ? false : strrpos($stat, ')');

        if (false === $stat || false === $close) {
            return [];
        }

        // After the last parenthesis, because the command name between them may hold spaces and parentheses itself.
        return explode(' ', trim(substr($stat, $close + 1)));
    }
}
