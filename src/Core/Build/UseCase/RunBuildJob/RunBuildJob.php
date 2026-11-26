<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\TierResolutionFailure;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Build\Contract\BuildServices;
use Intakt\Core\Build\Domain\BuilderPrompt;
use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\Domain\GatesAtOnce;
use Intakt\Core\Build\Domain\QuestionRefused;
use Intakt\Core\Job\Contract\DockerEngineFailed;
use Intakt\Core\Job\Contract\EgressHosts;
use Intakt\Core\Job\Contract\JobCause;
use Intakt\Core\Job\Contract\JobCommand;
use Intakt\Core\Job\Contract\JobEgress;
use Intakt\Core\Job\Contract\JobEventNotRecorded;
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

    public const string QUESTION = 'question.json';

    public const string LOG = 'build.log';

    // An exit code, because a builder that can plant files in the output can plant any marker there too, but cannot set the script's exit code.
    public const int OUTPUT_NOT_CLEARED = 3;

    // Allowlisted with the gate's registries, because the CLI talks to this host only and the builder installs the project's dependencies.
    private const string VENDOR_HOST = 'api.anthropic.com';

    private const int MEMORY_BYTES = 4 * 1024 ** 3;

    private const float CPUS = 2.0;

    // Fixed, because sizing the limits for each task is plan task T60 (#342); the CLI ends its session after this many turns.
    private const int MAX_TURNS = 100;

    // Below the job's own limit, because the runner's kill keeps no output, while the script's cut still bundles what the session committed.
    private const int ALLOWANCE_SECONDS = 3000;

    // Beyond the allowance for the script's cleanup and bundle, so the runner's kill, which keeps no output, is a backstop that never fires first.
    private const int SECONDS = self::ALLOWANCE_SECONDS + 600;

    private int $toolTimeLimitSeconds;

    public function __construct(
        private TierResolver $tiers,
        private BuilderPrompt $prompts,
        private JobRunner $jobs,
        private Gate $gate,
        private GatesAtOnce $gatesAtOnce,
        #[Autowire(param: BuildServices::TOOL_TIME_LIMIT)]
        mixed $toolTimeLimitSeconds,
        private Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%/docker/job/build.sh')]
        private string $buildScript,
        // Under the project folder like the jobs' output directories, because the engine mounts host paths.
        #[Autowire('%kernel.project_dir%/var/prompts')]
        private string $promptFolder,
        private RecordedBuildStops $stops,
    ) {
        // Checked as given, because the compiled container would turn a YAML true into a one-second limit before a typed parameter saw it.
        if (!is_int($toolTimeLimitSeconds)) {
            throw new InvalidArgumentException(sprintf('The builder\'s tool time limit must be a whole number of seconds, not %s.', get_debug_type($toolTimeLimitSeconds)));
        }
        // Below the allowance, because a tool call that outlives the session is cut with it anyway.
        if ($toolTimeLimitSeconds < 1 || $toolTimeLimitSeconds >= self::ALLOWANCE_SECONDS) {
            throw new InvalidArgumentException(sprintf('The builder\'s tool time limit must be at least 1 second and below the session\'s allowance of %d seconds, not %d.', self::ALLOWANCE_SECONDS, $toolTimeLimitSeconds));
        }
        $this->toolTimeLimitSeconds = $toolTimeLimitSeconds;
    }

    /**
     * @throws BuildCloneIsNoFolder
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

        $cause = null;
        $refused = false;

        try {
            $outcome = $this->outcomeOf($this->ranJob($this->specOf($job, $model, [new JobInput($clone, self::CLONE), new JobInput($prompt, self::PROMPT)])));
        } catch (JobRefused $failure) {
            $refused = true;

            throw $failure;
        } finally {
            // Read before the prompt is removed and also when the job failed, so a cause never reaches the branch's next build.
            // Not read for a refused job: it never started, and the cause belongs to the build that holds the branch's job id.
            if (!$refused) {
                $cause = $this->stops->causeFor($job);
            }
            $this->filesystem->remove($prompt);
        }

        return $outcome->stoppedFor($cause);
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
            throw BuildCloneIsNoFolder::at($path);
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

    /**
     * @param list<JobInput> $inputs
     */
    private function specOf(BuildJob $job, string $model, array $inputs): JobSpec
    {
        return new JobSpec(
            $job->jobId(),
            $job->image,
            new JobCommand('/bin/sh', '-c', $this->filesystem->readFile($this->buildScript), 'intakt-build', self::CLONE, self::PROMPT, $job->branch, $model, (string) self::MAX_TURNS, (string) self::ALLOWANCE_SECONDS, (string) $this->gatesAtOnce->loweringTheDefaultOfAJobWith(self::CPUS, self::MEMORY_BYTES), (string) $this->toolTimeLimitSeconds),
            $inputs,
            new JobLimits(self::MEMORY_BYTES, self::CPUS, self::SECONDS),
            JobEgress::to(new EgressHosts(self::VENDOR_HOST, ...$this->gate->registryHosts())),
        );
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

    private function outcomeOf(?JobResult $result): BuildJobOutcome
    {
        if (!$result instanceof JobResult) {
            return new BuildJobOutcome(BuildVerdict::NoResult);
        }

        $log = $result->output->contents(self::LOG) ?? '';

        return match ($result->end->cause) {
            JobCause::MemoryKilled => new BuildJobOutcome(BuildVerdict::MemoryKilled, log: $log),
            JobCause::TimedOut => new BuildJobOutcome(BuildVerdict::TimedOut, log: $log),
            // The builder cannot set the script's exit code, so the one code that says its files may still be in the output is trusted; what a build hands back is read from the output only.
            JobCause::Exited => self::OUTPUT_NOT_CLEARED === $result->end->exitCode
                ? new BuildJobOutcome(BuildVerdict::NoResult, log: $log)
                : $this->handedBack($result->output, $log),
        };
    }

    private function handedBack(JobOutput $output, string $log): BuildJobOutcome
    {
        $question = $output->contents(self::QUESTION);

        if (null !== $question) {
            return $this->asked($question, $log);
        }

        $bundle = $output->contents(self::BUNDLE);
        $text = $output->contents(self::PULL_REQUEST_TEXT);

        if (null === $bundle) {
            return new BuildJobOutcome(
                BuildVerdict::NoCommits,
                pullRequestText: $text,
                log: $log,
            );
        }

        if (null === $text) {
            return new BuildJobOutcome(BuildVerdict::NoPullRequestText, $bundle, log: $log);
        }

        return new BuildJobOutcome(BuildVerdict::Built, $bundle, $text, $log);
    }

    private function asked(string $text, string $log): BuildJobOutcome
    {
        try {
            return new BuildJobOutcome(
                BuildVerdict::Asked,
                log: $log,
                question: BuildQuestion::fromHandedBack($text),
            );
        } catch (QuestionRefused $refusal) {
            return new BuildJobOutcome(BuildVerdict::QuestionRefused, log: $log.'question refused: '.$refusal->getMessage()."\n");
        }
    }
}
