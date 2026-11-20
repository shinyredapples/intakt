<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\RunCheckJob;

use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\HandBack\Contract\AcceptedHandBack;
use Intakt\Core\HandBack\Contract\CheckJob;
use Intakt\Core\HandBack\Contract\CheckJobOutcome;
use Intakt\Core\HandBack\Contract\CheckJobs;
use Intakt\Core\HandBack\Contract\CheckVerdict;
use Intakt\Core\HandBack\Contract\HandBackBranchRefused;
use Intakt\Core\HandBack\Contract\HandBackNotChecked;
use Intakt\Core\HandBack\Contract\RefusedHandBack;
use Intakt\Core\HandBack\UseCase\CheckHandBack\CheckHandBack;
use Intakt\Core\Job\Contract\DockerEngineFailed;
use Intakt\Core\Job\Contract\JobCause;
use Intakt\Core\Job\Contract\JobEgress;
use Intakt\Core\Job\Contract\JobEventNotRecorded;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobInput;
use Intakt\Core\Job\Contract\JobLimits;
use Intakt\Core\Job\Contract\JobOutput;
use Intakt\Core\Job\Contract\JobOutputRefused;
use Intakt\Core\Job\Contract\JobRefused;
use Intakt\Core\Job\Contract\JobResult;
use Intakt\Core\Job\Contract\JobRunner;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Plugin\Gate\Contract\Gate;
use NoDiscard;
use Override;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

final readonly class RunCheckJob implements CheckJobs
{
    public const string MIRROR = '/input/mirror';

    public const string BUNDLE = '/input/handback.bundle';

    public const string RESULT = 'check-result';

    public const string LOG = 'check.log';

    // Room for a fresh dependency install and a whole project check, which a build's limits would not leave.
    private const int MEMORY_BYTES = 4 * 1024 ** 3;

    private const float CPUS = 2.0;

    private const int SECONDS = 1800;

    // Exactly what docker/job/check.sh writes, so anything else the code under test leaves there fails the check.
    private const string WELL_FORMED = '/\A(?:passed|not-applied|conflicts-with-main|not-merged|failed ([1-9]\d{0,2}))\n\z/D';

    public function __construct(
        private CheckHandBack $handBacks,
        private LostContentCheck $lostContent,
        private JobRunner $jobs,
        private Gate $gate,
        private Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%/docker/job/check.sh')]
        private string $checkScript,
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
    public function run(CheckJob $job): CheckJobOutcome|RefusedHandBack
    {
        $accepted = $this->handBacks->check($job->handBack);

        if ($accepted instanceof RefusedHandBack) {
            return $accepted;
        }

        $repository = $this->absolute($job->handBack->repository);
        $lost = $this->lostContent->of($repository, $accepted->head, [...$job->namedByThePlan]);

        if ([] !== $lost) {
            return new CheckJobOutcome(CheckVerdict::LostContent, lostContent: $lost);
        }

        return $this->outcomeOf($this->ranJob($job, $repository, $accepted));
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

    private function ranJob(CheckJob $job, string $repository, AcceptedHandBack $accepted): ?JobResult
    {
        try {
            return $this->jobs->run($this->specOf($job, $repository, $accepted));
        } catch (JobOutputRefused) {
            // No result, because the job wrote what the runner refuses to read, which says nothing for the change.
            return null;
        }
    }

    private function specOf(CheckJob $job, string $repository, AcceptedHandBack $accepted): JobSpec
    {
        $script = $this->filesystem->readFile($this->checkScript);
        $steps = $this->gate->checkSteps($job->configuredCommand);

        return new JobSpec(
            new JobId('check-'.substr($accepted->head, 0, 40)),
            $job->image,
            ['/bin/sh', '-c', $script, 'intakt-check', self::MIRROR, self::BUNDLE, $job->handBack->branch, '--', ...$steps],
            [new JobInput($repository, self::MIRROR), new JobInput($this->absolute($job->handBack->bundle), self::BUNDLE)],
            new JobLimits(self::MEMORY_BYTES, self::CPUS, self::SECONDS),
            $this->egress(),
        );
    }

    private function egress(): JobEgress
    {
        $hosts = $this->gate->registryHosts();

        return [] === $hosts ? JobEgress::none() : JobEgress::to($hosts);
    }

    private function outcomeOf(?JobResult $result): CheckJobOutcome
    {
        if (!$result instanceof JobResult) {
            return new CheckJobOutcome(CheckVerdict::NoResult);
        }

        $log = $result->output->contents(self::LOG) ?? '';

        return match ($result->end->cause) {
            JobCause::MemoryKilled => new CheckJobOutcome(CheckVerdict::MemoryKilled, log: $log),
            JobCause::TimedOut => new CheckJobOutcome(CheckVerdict::TimedOut, log: $log),
            // The container's own exit code is not read, because the result leaves the job through its output directory only.
            JobCause::Exited => $this->reported($result->output, $log),
        };
    }

    private function reported(JobOutput $output, string $log): CheckJobOutcome
    {
        $result = $output->contents(self::RESULT) ?? '';

        if (1 !== preg_match(self::WELL_FORMED, $result, $match)) {
            return new CheckJobOutcome(CheckVerdict::NoResult, log: $log);
        }

        if (isset($match[1])) {
            return new CheckJobOutcome(CheckVerdict::Failed, (int) $match[1], log: $log);
        }

        return new CheckJobOutcome(CheckVerdict::from(trim($result)), log: $log);
    }
}
