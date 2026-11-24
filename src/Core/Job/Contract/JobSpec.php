<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

// Holds no environment and no mount but its inputs, so no forge credential or signing key can reach a job.
final readonly class JobSpec
{
    public const string WORK_AREA = '/work';
    public const string OUTPUT_DIRECTORY = '/output';
    public const string KEPT_FOLDER = '/kept';

    /**
     * @param list<JobInput> $inputs
     */
    public function __construct(
        public JobId $id,
        public string $image,
        public JobCommand $command,
        public array $inputs,
        public JobLimits $limits,
        public JobEgress $egress,
        public ?JobKeptFolder $kept = null,
    ) {
    }
}
