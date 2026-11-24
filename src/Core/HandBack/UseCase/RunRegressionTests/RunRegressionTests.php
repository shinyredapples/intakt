<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\RunRegressionTests;

use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\HandBack\Contract\CheckJob;
use Intakt\Core\HandBack\Contract\HandBackBranchRefused;
use Intakt\Core\HandBack\Contract\HandBackNotChecked;
use Intakt\Core\HandBack\Contract\RefusedHandBack;
use Intakt\Core\HandBack\Contract\RegressionRun;
use Intakt\Core\HandBack\Contract\RegressionRuns;
use Intakt\Core\HandBack\Contract\RegressionTests;
use Intakt\Core\HandBack\UseCase\CheckHandBack\CheckHandBack;
use Intakt\Core\Job\Contract\DockerEngineFailed;
use Intakt\Core\Job\Contract\JobCause;
use Intakt\Core\Job\Contract\JobCommand;
use Intakt\Core\Job\Contract\JobEgress;
use Intakt\Core\Job\Contract\JobEventNotRecorded;
use Intakt\Core\Job\Contract\JobInput;
use Intakt\Core\Job\Contract\JobLimits;
use Intakt\Core\Job\Contract\JobOutputRefused;
use Intakt\Core\Job\Contract\JobRefused;
use Intakt\Core\Job\Contract\JobResult;
use Intakt\Core\Job\Contract\JobRunner;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Plugin\Gate\Contract\DependencyStep;
use Intakt\Plugin\Gate\Contract\Gate;
use NoDiscard;
use Override;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

final readonly class RunRegressionTests implements RegressionTests
{
    public const string MIRROR = '/input/mirror';

    public const string BUNDLE = '/input/handback.bundle';

    public const string RESULT = 'regression-result';

    // Exactly what docker/job/regression.sh writes for a run that reached its tests, so anything else the code under test leaves there is no result.
    private const string WELL_FORMED = '/\A(?<run>passed|failed|errored)(?: (?<status>[1-9]\d{0,2}))?\n\z/D';

    private const int MEMORY_BYTES = 4 * 1024 ** 3;

    private const float CPUS = 2.0;

    private const int SECONDS = 1800;

    // Passed to the script where it has no install step or overlay, because an empty argument could not be told from a missing one.
    private const string NONE = '-';

    public function __construct(
        private CheckHandBack $handBacks,
        private RegressionTestFiles $testFiles,
        private JobRunner $jobs,
        private Gate $gate,
        private Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%/docker/job/regression.sh')]
        private string $script,
    ) {
    }

    /**
     * @throws HandBackBranchRefused
     * @throws HandBackNotChecked
     * @throws RepositoryAttributesRefused
     * @throws JobRefused
     * @throws DockerEngineFailed
     * @throws JobEventNotRecorded
     */
    #[Override]
    #[NoDiscard]
    public function run(CheckJob $job): RegressionRuns|RefusedHandBack
    {
        $accepted = $this->handBacks->check($job->handBack);

        if ($accepted instanceof RefusedHandBack) {
            return $accepted;
        }

        $repository = $this->absolute($job->handBack->repository);
        $base = $this->testFiles->baseOf($repository, $accepted->head);
        $tests = $this->testFiles->in($repository, $base, $accepted->head);

        if ($tests->isEmpty()) {
            return RegressionRuns::noTest();
        }

        // Two jobs of their own, because the base must fail and the head must pass, and one run could not tell which side a failure came from.
        $baseRun = $this->ranOn($job, $repository, RegressionCheckout::base($base, $accepted->head, $tests));

        try {
            $headRun = $this->ranOn($job, $repository, RegressionCheckout::head($accepted->head, $tests));
        } catch (JobRefused|DockerEngineFailed|JobEventNotRecorded $failure) {
            // A base that passed already shows the test proves nothing, so the failed head run must not discard it.
            if (RegressionRun::Passed !== $baseRun) {
                throw $failure;
            }

            $headRun = RegressionRun::NoResult;
        }

        return new RegressionRuns($tests, $baseRun, $headRun);
    }

    private function absolute(string $path): string
    {
        // The hand-back check found both, so a path that no longer resolves was removed under it.
        $absolute = realpath($path);

        if (false === $absolute) {
            throw HandBackNotChecked::in($path, 'it is gone');
        }

        return $absolute;
    }

    /**
     * @throws JobRefused
     * @throws DockerEngineFailed
     * @throws JobEventNotRecorded
     */
    private function ranOn(CheckJob $job, string $repository, RegressionCheckout $checkout): RegressionRun
    {
        $spec = new JobSpec(
            $checkout->id,
            $job->image,
            $this->commandOf($job, $checkout),
            [new JobInput($repository, self::MIRROR), new JobInput($this->absolute($job->handBack->bundle), self::BUNDLE)],
            new JobLimits(self::MEMORY_BYTES, self::CPUS, self::SECONDS),
            $this->egress(),
        );

        return $this->runOf($this->ranJob($spec));
    }

    private function commandOf(CheckJob $job, RegressionCheckout $checkout): JobCommand
    {
        $overlay = [...$checkout->overlay];
        $step = $this->gate->dependencyStep($job->configuredCommand);
        $install = $step instanceof DependencyStep ? $step->checkInstall : self::NONE;

        return new JobCommand(...[
            '/bin/sh',
            '-c',
            $this->filesystem->readFile($this->script),
            'intakt-regression',
            self::MIRROR,
            self::BUNDLE,
            $job->handBack->branch,
            $checkout->revision,
            [] === $overlay ? self::NONE : $job->handBack->branch,
            (string) count($overlay),
            $install,
            ...$overlay,
            ...$this->gate->testSteps($checkout->tests),
        ]);
    }

    private function egress(): JobEgress
    {
        $hosts = $this->gate->registryHosts();

        return $hosts->isEmpty() ? JobEgress::none() : JobEgress::to($hosts);
    }

    private function ranJob(JobSpec $spec): ?JobResult
    {
        try {
            return $this->jobs->run($spec);
        } catch (JobOutputRefused) {
            // No result, because the job wrote what the runner refuses to read, which says nothing for the change.
            return null;
        }
    }

    private function runOf(?JobResult $result): RegressionRun
    {
        if (!$result instanceof JobResult) {
            return RegressionRun::NoResult;
        }

        return match ($result->end->cause) {
            JobCause::TimedOut => RegressionRun::TimedOut,
            JobCause::MemoryKilled => RegressionRun::NoResult,
            // The container's own exit code is not read, because the result leaves the job through its output directory only.
            JobCause::Exited => $this->reported($result->output->contents(self::RESULT) ?? ''),
        };
    }

    private function reported(string $result): RegressionRun
    {
        if (1 !== preg_match(self::WELL_FORMED, $result, $match, PREG_UNMATCHED_AS_NULL)) {
            return RegressionRun::NoResult;
        }

        // Only an errored run names its status, and the script reports status 1 as failed and can exit with no status above 255, so "failed 1", a bare "errored", "errored 1" and "errored 256" are no result.
        $status = null === $match['status'] ? null : (int) $match['status'];

        return match (true) {
            'passed' === $match['run'] && null === $status => RegressionRun::Passed,
            'failed' === $match['run'] && null === $status => RegressionRun::Failed,
            'errored' === $match['run'] && null !== $status && $status >= 2 && $status <= 255 => RegressionRun::Errored,
            default => RegressionRun::NoResult,
        };
    }
}
