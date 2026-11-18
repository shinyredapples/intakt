<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

use Symfony\Component\Filesystem\Path;

// Holds no environment and no mount but its inputs and the one kept directory, so no forge credential or signing key can reach a job.
final readonly class JobSpec
{
    public const string WORK_AREA = '/work';
    public const string OUTPUT_DIRECTORY = '/output';
    public const string KEPT_DIRECTORY = '/kept';

    /**
     * @param list<string>   $command
     * @param list<JobInput> $inputs
     */
    public function __construct(
        public JobId $id,
        public string $image,
        public array $command,
        public array $inputs,
        public JobLimits $limits,
        public JobEgress $egress,
        // A host directory the job fills and the caller keeps, for what is too large for the output directory.
        public ?string $keptDirectory = null,
    ) {
        if (null !== $keptDirectory && !Path::isAbsolute($keptDirectory)) {
            throw JobRefused::forKeptDirectory($keptDirectory);
        }
    }
}
