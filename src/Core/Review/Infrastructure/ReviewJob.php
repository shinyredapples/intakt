<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
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
use Intakt\Core\Review\Contract\ReviewJobRequest;
use InvalidArgumentException;
use NoDiscard;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

final readonly class ReviewJob
{
    public const string CHECKOUT = '/input/checkout';

    public const string PROMPT = '/input/prompt.md';

    public const string ANSWER = 'answer';

    public const string LOG = 'review.log';

    private const string REFUSAL = 'refused: ';

    private const int MEMORY_BYTES = 4 * 1024 ** 3;

    private const float CPUS = 2.0;

    // Fixed, because sizing the limits for each review is plan task T60 (#1710).
    private const int SECONDS = 1800;

    public function __construct(
        private ReviewJobImages $images,
        private JobRunner $jobs,
        private Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%/docker/job/review.sh')]
        private string $reviewScript,
        // Under the project folder like the jobs' output directories, because the engine mounts host paths.
        #[Autowire('%kernel.project_dir%/var/prompts')]
        private string $promptFolder,
    ) {
    }

    /**
     * @throws ReviewJobFailure
     * @throws InvalidArgumentException
     * @throws JobRefused
     * @throws DockerEngineFailed
     * @throws JobEventNotRecorded
     */
    #[NoDiscard]
    public function run(ReviewJobRequest $request): ReviewJobAnswer
    {
        $egress = $this->egressOf($request->model->provider);
        $image = $this->images->imageFor($request->project);
        $checkout = new JobInput($this->folder($request->checkout), self::CHECKOUT);
        $prompt = $this->promptFileFor($request);

        try {
            $spec = new JobSpec($this->idOf($request), $image, $this->commandFor($request), [$checkout, new JobInput($prompt, self::PROMPT)], new JobLimits(self::MEMORY_BYTES, self::CPUS, self::SECONDS), $egress);

            return $this->answerOf($request->model->provider, $this->ranJob($spec));
        } finally {
            $this->filesystem->remove($prompt);
        }
    }

    // The vendor's host alone, because the review image installs nothing and the CLI talks to no other host.
    private function egressOf(Provider $provider): JobEgress
    {
        return match ($provider) {
            Provider::ClaudeCode => JobEgress::to(['api.anthropic.com']),
            Provider::Codex => JobEgress::to(['chatgpt.com']),
            default => throw ReviewJobFailure::withoutCli($provider),
        };
    }

    private function folder(string $path): string
    {
        $absolute = realpath($path);

        if (false === $absolute || !is_dir($absolute)) {
            throw new InvalidArgumentException(sprintf('The review checkout "%s" is no folder.', $path));
        }

        return $absolute;
    }

    // A file of its own for each run, readable by the orchestrator's user only, so two reviews never share one.
    private function promptFileFor(ReviewJobRequest $request): string
    {
        $this->filesystem->mkdir($this->promptFolder, 0o700);
        $file = $this->filesystem->tempnam($this->promptFolder, 'review-', '.md');
        $this->filesystem->appendToFile($file, $request->prompt);

        return $file;
    }

    // Length-prefixed, so no project, head and reviewer names another's job; one id each, so the runner refuses a second run of the same reviewer on the same head while one runs.
    private function idOf(ReviewJobRequest $request): JobId
    {
        $parts = [$request->project, $request->head, $request->reviewer, $request->model->provider->value];
        $key = implode('', array_map(static fn (string $part): string => strlen($part).':'.$part, $parts));

        return new JobId('review-'.substr(hash('sha256', $key), 0, 40));
    }

    /**
     * @return list<string>
     */
    private function commandFor(ReviewJobRequest $request): array
    {
        return ['/bin/sh', '-c', $this->filesystem->readFile($this->reviewScript), 'intakt-review', self::CHECKOUT, $request->head, self::PROMPT, $request->model->provider->value, $request->model->model];
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

    private function answerOf(Provider $provider, ?JobResult $result): ReviewJobAnswer
    {
        if (!$result instanceof JobResult) {
            throw ReviewJobFailure::withoutResult();
        }

        return match ($result->end->cause) {
            JobCause::MemoryKilled => throw ReviewJobFailure::outOfMemory(),
            JobCause::TimedOut => throw ReviewJobFailure::outOfTime(),
            // The CLI cannot set the script's exit code, which is not 0 only for a refusal before the CLI ran.
            JobCause::Exited => 0 === $result->end->exitCode
                ? $this->answered($provider, $result->output)
                : throw ReviewJobFailure::refused($this->refusalIn($result->output) ?? sprintf('it exited with %d', $result->end->exitCode)),
        };
    }

    private function answered(Provider $provider, JobOutput $output): ReviewJobAnswer
    {
        $status = $this->cliStatusIn($provider, $output);

        if (0 !== $status) {
            throw ReviewJobFailure::cliFailed($provider, $status);
        }

        return ReviewJobAnswer::read($provider, $output->contents(self::ANSWER) ?? '');
    }

    // The script refuses before the CLI runs, so the log holds only its own lines and git's.
    private function refusalIn(JobOutput $output): ?string
    {
        $refusals = array_filter(explode("\n", $output->contents(self::LOG) ?? ''), static fn (string $line): bool => str_starts_with($line, self::REFUSAL));
        $last = array_last($refusals);

        return null === $last ? null : substr($last, strlen(self::REFUSAL));
    }

    // The last such line, because the script writes it after the CLI ended, and the CLI's own errors in the log come before it.
    private function cliStatusIn(Provider $provider, JobOutput $output): int
    {
        preg_match_all('/^'.preg_quote($provider->value, '/').' exited with (\d+)$/m', $output->contents(self::LOG) ?? '', $exits);
        $status = array_last($exits[1]);

        return null === $status ? throw ReviewJobFailure::withoutCliExit($provider) : (int) $status;
    }
}
