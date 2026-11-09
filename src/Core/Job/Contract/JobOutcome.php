<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

final readonly class JobOutcome
{
    /**
     * @param list<string> $egressRefusals
     */
    public function __construct(
        public string $jobId,
        public int $exitCode,
        public JobEndCause $cause,
        public StartCheckReport $startCheck,
        public array $egressRefusals,
        public string $outputDir,
    ) {
    }
}
