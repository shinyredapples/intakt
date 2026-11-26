<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Build\Domain\BuildStopCause;
use Symfony\Contracts\Service\ResetInterface;

// Only tests call record() in this part, because the watch of #3965 that stops a running build is its production caller; RunBuildJob reads the cause after the job ended.
final class BuildStops implements ResetInterface
{
    /** @var array<string, BuildStopCause> */
    private array $causes = [];

    public function record(BuildJob $job, BuildStopCause $cause): void
    {
        $this->causes[$this->keyOf($job)] = $cause;
    }

    // Forgotten once read, so a later build of the same branch never inherits an earlier stop.
    public function causeFor(BuildJob $job): ?BuildStopCause
    {
        $key = $this->keyOf($job);
        $cause = $this->causes[$key] ?? null;
        unset($this->causes[$key]);

        return $cause;
    }

    public function reset(): void
    {
        $this->causes = [];
    }

    // Length-prefixed, so no project and branch pair names another pair's job.
    private function keyOf(BuildJob $job): string
    {
        return strlen($job->project).':'.$job->project.$job->branch;
    }
}
