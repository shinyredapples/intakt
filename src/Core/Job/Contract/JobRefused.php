<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

use InvalidArgumentException;

final class JobRefused extends InvalidArgumentException
{
    public static function forId(string $id): self
    {
        return new self(sprintf('A job id is 1 to 63 lowercase letters, digits, dots, dashes or underscores, starting with a letter or digit; "%s" is not.', $id));
    }

    public static function forLimits(): self
    {
        return new self('A job needs a positive memory limit, CPU share and run time.');
    }

    public static function forInput(string $hostPath, string $containerPath): self
    {
        return new self(sprintf('An input maps an absolute host path to an absolute container path outside %s and %s; "%s" to "%s" does not.', JobSpec::WORK_AREA, JobSpec::OUTPUT_DIRECTORY, $hostPath, $containerPath));
    }

    public static function alreadyRunning(JobId $id): self
    {
        return new self(sprintf('The job "%s" is already running.', $id->value));
    }
}
