<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

// Reads a process's parent from Linux's /proc, so a journey can tell a consumer's worker from a process that later got the same PID.
final readonly class ProcParents
{
    public function parentOf(int $pid): ?int
    {
        $file = '/proc/'.$pid.'/stat';
        $stat = is_readable($file) ? file_get_contents($file) : false;
        $close = false === $stat ? false : strrpos($stat, ')');

        if (false === $stat || false === $close) {
            return null;
        }

        // After the last parenthesis, because the command name between them may hold spaces and parentheses itself.
        $fields = explode(' ', trim(substr($stat, $close + 1)));

        return isset($fields[1]) && ctype_digit($fields[1]) ? (int) $fields[1] : null;
    }
}
