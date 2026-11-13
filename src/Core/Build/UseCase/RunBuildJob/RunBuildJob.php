<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\TierResolutionFailure;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Build\Domain\BuilderPrompt;
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
use InvalidArgumentException;
use NoDiscard;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

final readonly class RunBuildJob
{
    public const string CLONE = '/input/clone';

    public const string PROMPT = '/input/prompt.md';

    public const string BUNDLE = 'handback.bundle';

    public const string PULL_REQUEST_TEXT = 'pull-request.md';

    public const string LOG = 'build.log';

    // The Claude Code CLI's only host, and the builder installs the project's dependencies from the gate's registries.
    private const string VENDOR_HOST = 'api.anthropic.com';

    private const int MEMORY_BYTES = 4 * 1024 ** 3;

    private const float CPUS = 2.0;

    // A bound on the whole session until the turn limit and the allowance bound it (plan task T49, part 2).
    private const int SECONDS = 3600;

    public function __construct(
        private TierResolver $tiers,
        private BuilderPrompt $prompts,
        private JobRunner $jobs,
        private Gate $gate,
        private Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%/docker/job/build.sh')]
        private string $buildScript,
        // Under the project folder like the jobs' output directories, because the engine mounts host paths.
        #[Autowire('%kernel.project_dir%/var/prompts')]
        private string $promptFolder,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     * @throws TierResolutionFailure
     * @throws BuilderNotOnClaudeCode
     * @throws JobRefused
     * @throws DockerEngineFailed
     * @throws JobEventNotRecorded
     */
    #[NoDiscard]
    public function run(BuildJob $job): BuildJobOutcome
    {
        $model = $this->modelFor($job);
        $clone = $this->folder($job->clone);
        $prompt = $this->promptFileFor($job);

        try {
            return $this->outcomeOf($this->ranJob($this->specOf($job, $clone, $prompt, $model)));
        } finally {
            $this->filesystem->remove($prompt);
        }
    }

    private function modelFor(BuildJob $job): string
    {
        $resolved = $this->tiers->resolve($job->project, $job->tier);

        if (Provider::ClaudeCode !== $resolved->provider) {
            throw new BuilderNotOnClaudeCode($job->project, $job->tier, $resolved->provider);
        }

        return $resolved->model;
    }

    private function folder(string $path): string
    {
        $absolute = realpath($path);

        if (false === $absolute || !is_dir($absolute)) {
            throw new InvalidArgumentException(sprintf('The build clone "%s" is no folder.', $path));
        }

        return $absolute;
    }

    // A file of its own for each run, readable by the orchestrator's user only, so two builds never share one.
    private function promptFileFor(BuildJob $job): string
    {
        $this->filesystem->mkdir($this->promptFolder, 0o700);
        $file = $this->filesystem->tempnam($this->promptFolder, 'prompt-', '.md');
        $this->filesystem->appendToFile($file, $this->prompts->textFor($job->brief));

        return $file;
    }

    private function ranJob(JobSpec $spec): ?JobResult
    {
        try {
            return $this->jobs->run($spec);
        } catch (JobOutputRefused) {
            // No result, because the job wrote what the runner refuses to read.
            return null;
        }
    }

    private function specOf(BuildJob $job, string $clone, string $prompt, string $model): JobSpec
    {
        return new JobSpec(
            $this->idOf($job),
            $job->image,
            ['/bin/sh', '-c', $this->filesystem->readFile($this->buildScript), 'intakt-build', self::CLONE, self::PROMPT, $job->branch, $model],
            [new JobInput($clone, self::CLONE), new JobInput($prompt, self::PROMPT)],
            new JobLimits(self::MEMORY_BYTES, self::CPUS, self::SECONDS),
            JobEgress::to([self::VENDOR_HOST, ...$this->gate->registryHosts()]),
        );
    }

    // Length-prefixed, so no project and branch pair names another pair's job; one id per pair, so the runner refuses a second build of a branch while one runs.
    private function idOf(BuildJob $job): JobId
    {
        return new JobId('build-'.substr(hash('sha256', strlen($job->project).':'.$job->project.$job->branch), 0, 40));
    }

    private function outcomeOf(?JobResult $result): BuildJobOutcome
    {
        if (!$result instanceof JobResult) {
            return new BuildJobOutcome(BuildVerdict::NoResult);
        }

        $log = $result->output->contents(self::LOG) ?? '';

        return match ($result->end->cause) {
            JobCause::MemoryKilled => new BuildJobOutcome(BuildVerdict::MemoryKilled, log: $log),
            JobCause::TimedOut => new BuildJobOutcome(BuildVerdict::TimedOut, log: $log),
            // The container's exit code is not read, because what a build hands back leaves the job through its output directory only.
            JobCause::Exited => $this->handedBack($result->output, $log),
        };
    }

    private function handedBack(JobOutput $output, string $log): BuildJobOutcome
    {
        $bundle = $output->contents(self::BUNDLE);
        $text = $output->contents(self::PULL_REQUEST_TEXT);

        if (null === $bundle) {
            return new BuildJobOutcome(BuildVerdict::NoCommits, pullRequestText: $text, log: $log);
        }

        if (null === $text) {
            return new BuildJobOutcome(BuildVerdict::NoPullRequestText, $bundle, log: $log);
        }

        return new BuildJobOutcome(BuildVerdict::Built, $bundle, $text, $log);
    }
}
