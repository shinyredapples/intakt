<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Build\Domain\BuildLimits;
use Intakt\Core\Build\Domain\BuildProgress;
use Intakt\Core\Build\Domain\BuildStopCause;
use Intakt\Core\Build\Domain\BuildStopRule;
use Intakt\Core\Build\Domain\BuildWatch;
use Intakt\Core\Job\Contract\JobFollowedFile;
use Intakt\Core\Job\Contract\JobOutputPiece;
use Intakt\Core\Job\Contract\JobWatch;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// One build's look at itself while its job runs: asked at an interval, it reads what the log gained since the last look, records the cause on the first stop and says so, and the runner ends the session.
#[Exclude]
final class BuildSessionWatch implements JobWatch
{
    // Thirty seconds, because the idle limit is counted in minutes.
    public const int INTERVAL_SECONDS = 30;

    // A look reads at most this much, so the orchestrator's memory stays bounded however much the session writes; the rest is read at the next looks.
    public const int PIECE_BYTES = 4 * 1024 * 1024;

    // A longer line is skipped, because the builder can write a line that never ends; the CLI's own lines stay far below it, since it cuts what a tool returns.
    public const int LINE_BYTES = 1024 * 1024;

    private BuildWatch $watch;

    private readonly BuildLogTally $tally;

    // Only ever raised, because the log is the builder's to write: a lower figure it plants must never lower what was seen, and the ceiling and the task total depend on this one.
    private int $tokens = 0;

    private int $offset = 0;

    private ?string $identity = null;

    private string $partial = '';

    private bool $skippingLine = false;

    // Set for good once the log that was seen is gone, replaced, shortened or locked, because from then on the session's tokens can no longer be seen.
    private bool $lost = false;

    public function __construct(
        private readonly BuildJob $job,
        private readonly BuildStopRule $rule,
        private readonly RecordedBuildStops $stops,
        private readonly BuildLimits $limits,
    ) {
        $this->tally = new BuildLogTally();
        // From the reading of an empty log, so the first look at a log that shows no change is no progress.
        $this->watch = $this->rule->startWatching($limits, new BuildProgress(0, 0, $this->tally->reading()->contentFingerprint, [], false));
    }

    public function intervalSeconds(): int
    {
        return self::INTERVAL_SECONDS;
    }

    public function followedFile(): string
    {
        return RunBuildJob::LOG;
    }

    public function endsSession(JobFollowedFile $file): bool
    {
        if (!$this->lost) {
            $this->follow($file->read($this->offset, self::PIECE_BYTES));
        }

        $reading = $this->tally->reading();
        $this->tokens = max($this->tokens, $reading->tokens);
        // A lost log counts as a session at its ceiling, so removing, replacing or locking the log stops the build instead of freezing what the watch sees.
        $sessionTokens = $this->lost ? max($this->tokens, $this->limits->ceilingTokens) : $this->tokens;
        $progress = new BuildProgress($sessionTokens, BuildLogEvents::plus($this->job->earlierTaskTokens, $sessionTokens), $reading->contentFingerprint, $reading->failingTests, $reading->checkRunning);
        $this->watch = $this->rule->observe($this->watch, $progress);
        $cause = $this->rule->stopCause($this->watch);

        if (!$cause instanceof BuildStopCause) {
            return false;
        }

        $this->stops->record($this->job, $cause);

        return true;
    }

    // Read once the job ended, because the build job's ledger row keeps it for the task's later builds.
    public function tokensUsed(): int
    {
        return $this->tokens;
    }

    // The CLI only appends to the log, so a log that is another file, or shorter than what was read, was changed by the builder; a missing one too, because the job's script creates it before anything else runs, long before the first look.
    private function follow(?JobOutputPiece $piece): void
    {
        $this->lost = !$piece instanceof JobOutputPiece || $this->isAnotherLog($piece);

        if ($this->lost || !$piece instanceof JobOutputPiece) {
            return;
        }

        $this->identity = $piece->identity;
        $this->offset += strlen($piece->bytes);
        $this->takeLines($piece->bytes);
    }

    private function isAnotherLog(JobOutputPiece $piece): bool
    {
        return (null !== $this->identity && $piece->identity !== $this->identity) || $piece->size < $this->offset;
    }

    // A line the last piece cut is finished by this one, so only whole lines are read.
    private function takeLines(string $bytes): void
    {
        $lines = explode("\n", $this->partial.$bytes);
        $this->partial = array_pop($lines);

        if ($this->skippingLine && [] !== $lines) {
            array_shift($lines);
            $this->skippingLine = false;
        }

        if ($this->skippingLine || strlen($this->partial) > self::LINE_BYTES) {
            [$this->partial, $this->skippingLine] = ['', true];
        }

        foreach ($lines as $line) {
            $event = BuildLogEvents::decode($line);

            if (null !== $event) {
                $this->tally->take($event);
            }
        }
    }
}
