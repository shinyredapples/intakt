<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Job\Contract\JobCause;
use Intakt\Core\Job\Contract\JobEnd;
use Intakt\Core\Job\Contract\JobRefused;
use Intakt\Core\Job\Contract\JobSpec;
use Symfony\Component\Clock\ClockInterface;

final readonly class JobContainers
{
    // Ten seconds, because the engine needs a moment to deliver SIGKILL and record the exit, and the slot must not stay held.
    private const int KILL_GRACE_SECONDS = 10;

    // One second, so a connection that breaks at once is not called again in a busy loop until the run time ends.
    private const int RECONNECT_PAUSE_SECONDS = 1;

    public function __construct(
        private DockerEngine $engine,
        private JobContainerDefinition $definition,
        private ClockInterface $clock,
    ) {
    }

    public function runToEnd(JobSpec $spec, string $outputDirectory): JobEnd
    {
        $this->refuseImageVolumes($spec);
        $container = $this->engine->create('intakt-job-'.$spec->id->value, $this->definition->of($spec, $outputDirectory));

        try {
            $this->engine->start($container);

            return $this->awaitEnd($container, $this->clock->now()->modify(sprintf('+%d seconds', $spec->limits->seconds)));
        } finally {
            $this->engine->remove($container);
        }
    }

    // A volume the image declares stays writable under ReadonlyRootfs, so the job could write outside its work area and output directory.
    private function refuseImageVolumes(JobSpec $spec): void
    {
        $volumes = $this->engine->imageVolumes($spec->image);

        if ([] !== $volumes) {
            throw JobRefused::forImageVolumes($spec->image, $volumes);
        }
    }

    // The state decides, never the wait's answer, because the engine answers a wait that failed on its side with 200 while the job still runs.
    private function awaitEnd(string $container, DateTimeImmutable $deadline): JobEnd
    {
        // Never called with zero seconds left, because the HTTP client reads a zero duration as no limit.
        while (($seconds = $this->secondsUntil($deadline)) > 0.0) {
            $this->engine->waitForExit($container, $seconds);
            $state = $this->engine->state($container);

            if (!$state->running) {
                return $this->endOf($state);
            }

            $this->clock->sleep(min(self::RECONNECT_PAUSE_SECONDS, $this->secondsUntil($deadline)));
        }

        return $this->killed($container);
    }

    private function secondsUntil(DateTimeImmutable $deadline): float
    {
        return max(0.0, (float) $deadline->format('U.u') - (float) $this->clock->now()->format('U.u'));
    }

    private function killed(string $container): JobEnd
    {
        $this->engine->kill($container);
        $this->engine->waitForExit($container, self::KILL_GRACE_SECONDS);

        return new JobEnd(JobCause::TimedOut, $this->engine->state($container)->exitCode);
    }

    private function endOf(ContainerState $state): JobEnd
    {
        return new JobEnd($state->memoryKilled ? JobCause::MemoryKilled : JobCause::Exited, $state->exitCode);
    }
}
