<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use Intakt\Core\Job\Contract\JobOutput;
use Intakt\Core\Job\Contract\JobOutputFile;

// Lets a test write a job's output as file name and contents, the way the job leaves it on disk.
final class OutputFiles
{
    /**
     * @param array<array-key, string> $files
     */
    public static function of(array $files): JobOutput
    {
        return new JobOutput(...array_map(static fn (int|string $name, string $contents): JobOutputFile => new JobOutputFile((string) $name, $contents), array_keys($files), $files));
    }
}
