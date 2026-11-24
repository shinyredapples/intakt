<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class TimeLimit
{
    private const int CANNOT_RUN = 127;

    // Because a process given an infinite or NAN limit would run without any effective timeout.
    public static function allows(float $seconds): bool
    {
        return is_finite($seconds) && $seconds > 0;
    }

    public static function refusal(string $program, float $seconds): ProcessResult
    {
        return is_finite($seconds) ? self::exceeded($program, $seconds) : new ProcessResult(self::CANNOT_RUN, '', 'cannot run '.$program.': its time limit is not a finite number of seconds');
    }

    public static function exceeded(string $program, float $seconds): ProcessResult
    {
        return new ProcessResult(ProcessRunner::TIMED_OUT, '', $program.' timed out after '.$seconds.' seconds');
    }
}
