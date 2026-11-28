<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\TierResolutionFailure;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Build\Contract\BuildServices;
use Intakt\Core\Build\Domain\BuilderPrompt;
use Intakt\Core\Build\Domain\BuildLimits;
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

    // Covers a failed listing too, because the exception does not say which refusal it was.
    private const string REFUSED_OUTPUT = "no result: the runner refused the job's output: it could not be listed, or an entry in it is not a plain file or could not be read, or it passed the limit on entries or bytes\n";

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
        public BuildLimits $limits,
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
     * @throws BuildJobEndFailed
     */
    #[NoDiscard]
    public function run(BuildJob $job): BuildJobOutcome
    {
        $model = $this->modelFor($job);
        $clone = new JobInput($this->folder($job->clone), self::CLONE);
        // The command is built before the prompt file exists, so a build script that cannot be read leaves no prompt behind.
        $command = $this->commandOf($job, $model);
        $prompt = $this->emptyPromptFile();

        $cause = null;
        $reachedRunner = false;

        try {
            // Written and turned into the spec inside the try, because once the prompt file exists every failure must remove it, a failed write and a refused input included.
            $this->filesystem->appendToFile($prompt, $this->prompts->textFor($job->brief));
            $spec = $this->specOf($job, $command, $clone, new JobInput($prompt, self::PROMPT));
            // Set only here, so whatever fails before the runner is called, of any exception class, never reads the cause.
            $reachedRunner = true;
            $outcome = $this->outcomeOfRunning($spec);
        } catch (JobRefused $failure) {
            // Cleared, because a job the runner refused never started, so the cause belongs to the build that holds the branch's job id.
            $reachedRunner = false;

            throw $failure;
        } catch (DockerEngineFailed|JobEventNotRecorded $failure) {
            // Read here and cleared for the finally, so the cause is still read once and reaches the failed build_job row with the failure.
            $reachedRunner = false;

            throw BuildJobEndFailed::after($failure, $this->stops->causeFor($job));
        } finally {
            // Read only for a job the runner was called with and did not refuse, also when that job failed, so its cause never reaches the branch's next build; read before the prompt is removed, so a failed removal cannot keep it.
            if ($reachedRunner) {
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

    private function commandOf(BuildJob $job, string $model): JobCommand
    {
        return new JobCommand('/bin/sh', '-c', $this->filesystem->readFile($this->buildScript), 'intakt-build', self::CLONE, self::PROMPT, $job->branch, $model, (string) self::MAX_TURNS, (string) self::ALLOWANCE_SECONDS, (string) $this->gatesAtOnce->loweringTheDefaultOfAJobWith(self::CPUS, self::MEMORY_BYTES), (string) $this->toolTimeLimitSeconds);
    }

    // A file of its own for each run, readable by the orchestrator's user only, so two builds never share one.
    private function emptyPromptFile(): string
    {
        $this->filesystem->mkdir($this->promptFolder, 0o700);

        return $this->filesystem->tempnam($this->promptFolder, 'prompt-', '.md');
    }

    private function specOf(BuildJob $job, JobCommand $command, JobInput ...$inputs): JobSpec
    {
        $egress = JobEgress::to(new EgressHosts(self::VENDOR_HOST, ...$this->gate->registryHosts()));

        return new JobSpec($job->jobId(), $job->image, $command, array_values($inputs), new JobLimits(self::MEMORY_BYTES, self::CPUS, self::SECONDS), $egress);
    }

    private function outcomeOfRunning(JobSpec $spec): BuildJobOutcome
    {
        try {
            $result = $this->jobs->run($spec);
        } catch (JobOutputRefused) {
            // The reason is fixed because the job's log is part of what was refused, its standard error is the builder's to write, and the runner's message quotes a name the builder chose.
            return new BuildJobOutcome(BuildVerdict::NoResult, log: self::REFUSED_OUTPUT);
        }

        return $this->outcomeOf($result);
    }

    private function outcomeOf(JobResult $result): BuildJobOutcome
    {
        $log = $result->output->contents(self::LOG) ?? '';

        return match ($result->end->cause) {
            JobCause::MemoryKilled => new BuildJobOutcome(BuildVerdict::MemoryKilled, log: $log),
            JobCause::TimedOut => new BuildJobOutcome(BuildVerdict::TimedOut, log: $log),
            // The builder cannot set the script's exit code, so the one code that says its files may still be in the output is trusted; what a build hands back is read from the output only.
            JobCause::Exited => self::OUTPUT_NOT_CLEARED === $result->end->exitCode
                ? new BuildJobOutcome(BuildVerdict::NoResult, log: $this->withLineEnd($log)."no result: the build script could not clear the job's output\n")
                : $this->handedBack($result->output, $log),
        };
    }

    // The builder's last line may lack its end, and the trusted line that follows must stand on a line of its own.
    private function withLineEnd(string $log): string
    {
        return '' === $log || str_ends_with($log, "\n") ? $log : $log."\n";
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
