<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use Intakt\Core\Job\Contract\JobOutput;
use Intakt\Core\Job\Contract\JobOutputFile;

final class OutputFiles
{
    /**
     * @param array<array-key, string> $files
     */
    public static function of(array $files): JobOutput
    {
        return new JobOutput(...array_map(static fn (string $name, string $contents): JobOutputFile => new JobOutputFile($name, $contents), array_map(strval(...), array_keys($files)), $files));
    }
}
