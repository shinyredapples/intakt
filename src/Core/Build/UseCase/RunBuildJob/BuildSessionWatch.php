<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Build\Domain\BuildStopCause;
use Intakt\Core\Build\Domain\BuildLimits;
use Intakt\Core\Build\Domain\BuildProgress;
use Intakt\Core\Build\Domain\BuildStopRule;
use Intakt\Core\Build\Domain\BuildWatch;
use Intakt\Core\Job\Contract\JobOutput;
use Intakt\Core\Job\Contract\JobWatch;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// One build's look at itself while its job runs: asked at an interval, it records the cause on the first stop and says so, and the runner ends the session.
#[Exclude]
final class BuildSessionWatch implements JobWatch
{
    // Thirty seconds, because the idle limit is counted in minutes and a look reads the whole log.
    public const int INTERVAL_SECONDS = 30;

    private BuildWatch $watch;

    // Only ever raised, because the log is the builder's to write: a lower figure it plants must never lower what was seen, and the ceiling and the task total depend on this one.
    private int $tokens = 0;

    public function __construct(
        private readonly BuildJob $job,
        private readonly BuildStopRule $rule,
        private readonly RecordedBuildStops $stops,
        BuildLimits $limits,
    ) {
        // From the reading of an empty log, so the first look at a log that shows no change is no progress.
        $this->watch = $this->rule->startWatching($limits, new BuildProgress(0, 0, BuildLogReading::of('')->contentFingerprint, [], false));
    }

    public function intervalSeconds(): int
    {
        return self::INTERVAL_SECONDS;
    }

    public function endsSession(JobOutput $live): bool
    {
        $reading = BuildLogReading::of($live->contents(RunBuildJob::LOG) ?? '');
        $this->tokens = max($this->tokens, $reading->tokens);
        // The task's earlier continuations are not on the ledger yet, so the session's tokens stand for the task's.
        $progress = new BuildProgress($this->tokens, $this->tokens, $reading->contentFingerprint, $reading->failingTests, $reading->checkRunning);
        $this->watch = $this->rule->observe($this->watch, $progress);
        $cause = $this->rule->stopCause($this->watch);

        if (!$cause instanceof BuildStopCause) {
            return false;
        }

        $this->stops->record($this->job, $cause);

        return true;
    }
}
