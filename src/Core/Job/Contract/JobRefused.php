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
        return new self(sprintf('An input maps an absolute host path to an absolute container path outside %s, %s and %s; "%s" to "%s" does not.', JobSpec::WORK_AREA, JobSpec::OUTPUT_DIRECTORY, JobSpec::KEPT_FOLDER, $hostPath, $containerPath));
    }

    public static function forKeptFolder(string $hostPath): self
    {
        return new self(sprintf('A kept folder is an absolute host path; "%s" is not.', $hostPath));
    }

    /**
     * @param list<string> $volumes
     */
    public static function forImageVolumes(string $image, array $volumes): self
    {
        return new self(sprintf('The image "%s" declares the volumes %s, which a job could write outside %s and %s.', $image, implode(', ', $volumes), JobSpec::WORK_AREA, JobSpec::OUTPUT_DIRECTORY));
    }

    public static function forEmptyAllowlist(): self
    {
        return new self('An egress allowlist names at least one host; a job without network gets JobEgress::none().');
    }

    public static function forEgressHost(string $host): self
    {
        return new self(sprintf('An egress host is a lowercase host name with a top-level domain of letters, without wildcard or address; "%s" is not.', $host));
    }

    public static function alreadyRunning(JobId $id): self
    {
        return new self(sprintf('The job "%s" is already running.', $id->value));
    }
}
