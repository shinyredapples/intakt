<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Intakt\Core\Job\Contract\JobCause;
use Intakt\Core\Job\Contract\JobEnd;
use Intakt\Core\Job\Contract\JobSpec;

final readonly class JobContainers
{
    // Ten seconds, because the engine needs a moment to deliver SIGKILL and record the exit, and the slot must not stay held.
    private const int KILL_GRACE_SECONDS = 10;

    public function __construct(
        private DockerEngine $engine,
        private JobContainerDefinition $definition,
    ) {
    }

    public function runToEnd(JobSpec $spec, string $outputDirectory): JobEnd
    {
        $container = $this->engine->create('intakt-job-'.$spec->id->value, $this->definition->of($spec, $outputDirectory));

        try {
            $this->engine->start($container);

            return $this->awaitEnd($container, $spec->limits->seconds);
        } finally {
            $this->engine->remove($container);
        }
    }

    private function awaitEnd(string $container, int $seconds): JobEnd
    {
        $answered = $this->engine->waitForExit($container, $seconds);
        $state = $this->engine->state($container);

        if ($answered || !$state->running) {
            return $this->endOf($state);
        }

        $this->engine->kill($container);
        $this->engine->waitForExit($container, self::KILL_GRACE_SECONDS);

        return new JobEnd(JobCause::TimedOut, $this->engine->state($container)->exitCode);
    }

    private function endOf(ContainerState $state): JobEnd
    {
        return new JobEnd($state->memoryKilled ? JobCause::MemoryKilled : JobCause::Exited, $state->exitCode);
    }
}
