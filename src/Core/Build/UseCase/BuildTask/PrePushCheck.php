<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Intakt\Core\Build\Contract\TaskKind;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\HandBack\Contract\CheckJob;
use Intakt\Core\HandBack\Contract\CheckJobOutcome;
use Intakt\Core\HandBack\Contract\CheckJobs;
use Intakt\Core\HandBack\Contract\HandBack;
use Intakt\Core\HandBack\Contract\HandBackBranchRefused;
use Intakt\Core\HandBack\Contract\HandBackNotChecked;
use Intakt\Core\HandBack\Contract\RefusedHandBack;
use Intakt\Core\HandBack\Contract\RegressionRuns;
use Intakt\Core\HandBack\Contract\RegressionTests;
use Intakt\Core\Job\Contract\DockerEngineFailed;
use Intakt\Core\Job\Contract\JobEventNotRecorded;
use Intakt\Core\Job\Contract\JobRefused;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

// The project's check command on the bundle in a check job of its own, because the builder's own run of the checks is never trusted.
final readonly class PrePushCheck
{
    public function __construct(
        private CheckJobs $jobs,
        private HandBackFile $files,
        private RegressionTests $regressionTests,
        private LoggerInterface $logger,
    ) {
    }

    // Fixed text for each way the check fails, never its log, because the log holds what model-written code printed.
    public function failureOf(BuildTaskRequest $request, string $bundle, TaskKind $kind): ?string
    {
        return $this->files->check($request, $bundle, fn (HandBack $handBack): ?string => $this->failureIn($request, $handBack) ?? $this->reportedRegressionRuns($request, $handBack, $kind));
    }

    private function failureIn(BuildTaskRequest $request, HandBack $handBack): ?string
    {
        try {
            // The gate's own steps and no path named by the plan, because neither a project's check command nor plan approval is configured yet.
            $outcome = $this->jobs->run(new CheckJob($handBack, $request->image, null, new Paths()));
        } catch (ExceptionInterface $stopped) {
            // Fixed text and never the process's message, because that message names the whole command line.
            return $this->stoppedBy($stopped);
        } catch (HandBackBranchRefused|HandBackNotChecked|RepositoryAttributesRefused|JobRefused|DockerEngineFailed|JobEventNotRecorded $failure) {
            return $failure->getMessage();
        }

        return $outcome instanceof RefusedHandBack ? $outcome->reason() : $this->failureOfJob($outcome);
    }

    // Only reported for now, never part of the verdict, and only for a bug whose check passed, so a head that fails the check is not run twice more.
    private function reportedRegressionRuns(BuildTaskRequest $request, HandBack $handBack, TaskKind $kind): null
    {
        if (TaskKind::Bug !== $kind) {
            return null;
        }

        try {
            $runs = $this->regressionTests->run(new CheckJob($handBack, $request->image, null, new Paths()));
        } catch (ExceptionInterface|IOExceptionInterface|HandBackBranchRefused|HandBackNotChecked|RepositoryAttributesRefused|JobRefused|DockerEngineFailed|JobEventNotRecorded $failure) {
            // The job's script that cannot be read too, because a run that only reports must never fail a check that passed.
            $this->logger->warning('The regression tests of a bug head could not run.', ['project' => $request->project, 'exception' => $failure]);

            return null;
        }

        if ($runs instanceof RegressionRuns) {
            $this->logger->info('The regression tests of a bug head ran.', [
                'project' => $request->project,
                'tests' => [...$runs->tests],
                'base' => $runs->base?->value,
                'head' => $runs->head?->value,
            ]);
        }

        return null;
    }

    private function stoppedBy(ExceptionInterface $stopped): string
    {
        return match (true) {
            $stopped instanceof ProcessTimedOutException => sprintf('the pre-push check timed out after %s seconds', $stopped->getExceededTimeout()),
            $stopped instanceof ProcessSignaledException => sprintf('the pre-push check was killed by signal %d', $stopped->getSignal()),
            default => 'the pre-push check could not run',
        };
    }

    private function failureOfJob(CheckJobOutcome $outcome): ?string
    {
        if ($outcome->passed()) {
            return null;
        }

        return null === $outcome->exitCode ? $outcome->verdict->reason() : sprintf('%s (exit code %d)', $outcome->verdict->reason(), $outcome->exitCode);
    }
}
