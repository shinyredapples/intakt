<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class TimeLimit
{
    // Because a process given an infinite or NAN limit would run without any effective timeout.
    public static function allows(float $seconds): bool
    {
        return self::isFinite($seconds) && $seconds > 0;
    }

    // Because a finite limit as large as PHP_FLOAT_MAX overflows to INF in nanoseconds, which turns off a deadline kept on hrtime.
    public static function isFinite(float $seconds): bool
    {
        return is_finite($seconds * 1_000_000_000);
    }

    public static function refusal(string $program, float $seconds): ProcessResult
    {
        return self::isFinite($seconds) ? self::exceeded($program, $seconds) : new ProcessResult(ProcessRunner::CANNOT_RUN, '', 'cannot run '.$program.': its time limit is not a finite number of seconds');
    }

    public static function exceeded(string $program, float $seconds): ProcessResult
    {
        return new ProcessResult(ProcessRunner::TIMED_OUT, '', $program.' timed out after '.$seconds.' seconds');
    }
}
