<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

// The fixed places inside a job container; an input may not be mounted over any of them, because the job's work, home and output would be hidden.
final readonly class JobPaths
{
    public const string OUTPUT = '/output';
    public const string WORK = '/work';
    public const string HOME = '/home/job';
    public const string TMP = '/tmp';

    /**
     * @return list<string>
     */
    public static function reserved(): array
    {
        return [self::OUTPUT, self::WORK, self::HOME, self::TMP];
    }

    // The mount rules compare paths as strings, so a `.` or `..` segment would let a path name a place the rules never saw; a control character would split the start check's input list and its report lines.
    public static function isPlainAbsolute(string $path): bool
    {
        if (!str_starts_with($path, '/') || 1 === preg_match('/[\x00-\x1f\x7f]/', $path)) {
            return false;
        }

        return !array_any(explode('/', $path), static fn (string $segment): bool => '.' === $segment || '..' === $segment);
    }
}
