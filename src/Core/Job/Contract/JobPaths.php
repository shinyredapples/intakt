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
}
