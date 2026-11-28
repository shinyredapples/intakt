<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Build\Domain\BuildStopCause;
use Symfony\Contracts\Service\ResetInterface;

// Kept apart from the watch, because the watch records the cause while the job runs and RunBuildJob reads it only once the job ended, also when the job's end failed.
final class RecordedBuildStops implements ResetInterface
{
    /** @var array<string, BuildStopCause> */
    private array $causes = [];

    public function record(BuildJob $job, BuildStopCause $cause): void
    {
        $this->causes[$job->jobId()->value] = $cause;
    }

    // Forgotten once read, so a later build of the same branch never inherits an earlier build's stop.
    public function causeFor(BuildJob $job): ?BuildStopCause
    {
        $key = $job->jobId()->value;
        $cause = $this->causes[$key] ?? null;
        unset($this->causes[$key]);

        return $cause;
    }

    public function reset(): void
    {
        $this->causes = [];
    }
}
