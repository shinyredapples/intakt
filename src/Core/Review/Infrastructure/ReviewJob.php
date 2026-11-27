<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use DateTimeInterface;
use Intakt\Core\Ai\Contract\AccountLogin;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Job\Contract\DockerEngineFailed;
use Intakt\Core\Job\Contract\EgressHosts;
use Intakt\Core\Job\Contract\JobCause;
use Intakt\Core\Job\Contract\JobCommand;
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
use Intakt\Core\Platform\Contract\HeldCredentialSpans;
use Intakt\Core\Platform\Contract\RedactedSpans;
use Intakt\Core\Platform\Contract\Redaction;
use Intakt\Core\Review\Contract\ReviewJobRequest;
use InvalidArgumentException;
use NoDiscard;
use RuntimeException;
use SensitiveParameter;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

final readonly class ReviewJob
{
    public const string CHECKOUT = '/input/checkout';

    public const string PROMPT = '/input/prompt.md';

    public const string LOGIN = '/input/login';

    public const string ANSWER = 'answer';

    public const string LOG = 'review.log';

    private const string REFUSAL = 'refused: ';

    private const int MEMORY_BYTES = 4 * 1024 ** 3;

    private const float CPUS = 2.0;

    // Fixed, because sizing the limits for each review is plan task T60 (#1710).
    private const int SECONDS = 1800;

    private const int LEFT_BEHIND_AFTER_SECONDS = self::SECONDS + 60;

    private const string PROMPT_PREFIX = 'review-';

    private const string LOGIN_PREFIX = 'login-';

    public function __construct(
        private ReviewJobImages $images,
        private JobRunner $jobs,
        private Filesystem $filesystem,
        private ClockInterface $clock,
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
        $this->removeInputsLeftBehind();
        $prompt = $this->inputFile(self::PROMPT_PREFIX, '.md', $request->prompt);
        $login = null;

        try {
            $login = $request->login instanceof AccountLogin ? $this->inputFile(self::LOGIN_PREFIX, '', $request->login->token) : null;
            $inputs = [$checkout, new JobInput($prompt, self::PROMPT), ...(null === $login ? [] : [new JobInput($login, self::LOGIN)])];
            $spec = new JobSpec($this->idOf($request), $image, $this->commandFor($request), $inputs, new JobLimits(self::MEMORY_BYTES, self::CPUS, self::SECONDS), $egress);

            return $this->answerOf($request, $this->ranJob($spec));
        } finally {
            $this->filesystem->remove(array_filter([$prompt, $login], is_string(...)));
        }
    }

    // The vendor's host alone, because the review image installs nothing and the CLI talks to no other host.
    private function egressOf(Provider $provider): JobEgress
    {
        return match ($provider) {
            Provider::ClaudeCode => JobEgress::to(new EgressHosts('api.anthropic.com')),
            Provider::Codex => JobEgress::to(new EgressHosts('chatgpt.com')),
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

    // Because a killed worker never reaches the finally that removes its login; past the job's time limit, so no running job still reads the file.
    private function removeInputsLeftBehind(): void
    {
        if (!is_dir($this->promptFolder)) {
            return;
        }
        $leftBehind = new Finder()->files()->in($this->promptFolder)->depth('== 0')->name([self::LOGIN_PREFIX.'*', self::PROMPT_PREFIX.'*'])->date('before '.$this->clock->now()->modify(sprintf('-%d seconds', self::LEFT_BEHIND_AFTER_SECONDS))->format(DateTimeInterface::ATOM));

        try {
            $this->filesystem->remove($leftBehind);
        } catch (IOException|RuntimeException) {
            // Ignored, because another worker may be removing the same file, and a file that stays is tried again before the next review.
        }
    }

    // A file of its own for each run, readable by the orchestrator's user only, so two reviews never share one; the login is a file too, because a command line is shown in process lists and messages.
    private function inputFile(string $prefix, string $suffix, #[SensitiveParameter] string $content): string
    {
        $this->filesystem->mkdir($this->promptFolder, 0o700);
        $file = $this->filesystem->tempnam($this->promptFolder, $prefix, $suffix);
        $this->filesystem->appendToFile($file, $content);

        return $file;
    }

    // Length-prefixed, so no project, head and reviewer names another's job; one id each, so the runner refuses a second run of the same reviewer on the same head while one runs.
    private function idOf(ReviewJobRequest $request): JobId
    {
        $parts = [$request->project, $request->head, $request->reviewer, $request->model->provider->value];
        $key = implode('', array_map(static fn (string $part): string => strlen($part).':'.$part, $parts));

        return new JobId('review-'.substr(hash('sha256', $key), 0, 40));
    }

    private function commandFor(ReviewJobRequest $request): JobCommand
    {
        return new JobCommand('/bin/sh', '-c', $this->filesystem->readFile($this->reviewScript), 'intakt-review', self::CHECKOUT, $request->head, self::PROMPT, $request->model->provider->value, $request->model->model, ...($request->login instanceof AccountLogin ? [self::LOGIN] : []));
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

    private function answerOf(ReviewJobRequest $request, ?JobResult $result): ReviewJobAnswer
    {
        if (!$result instanceof JobResult) {
            throw ReviewJobFailure::withoutResult();
        }

        return match ($result->end->cause) {
            JobCause::MemoryKilled => throw ReviewJobFailure::outOfMemory($this->usageIn($request, $result->output)),
            JobCause::TimedOut => throw ReviewJobFailure::outOfTime($this->usageIn($request, $result->output)),
            // The CLI cannot set the script's exit code, which is not 0 only for a refusal before the CLI ran.
            JobCause::Exited => 0 === $result->end->exitCode
                ? $this->answered($request, $result->output)
                : throw ReviewJobFailure::refused($this->refusalIn($request, $result->output) ?? sprintf('it exited with %d', $result->end->exitCode)),
        };
    }

    /**
     * @return list<TokenUsageInterface>
     */
    private function usageIn(ReviewJobRequest $request, JobOutput $output): array
    {
        return ReviewJobAnswer::usageIn($request->model->provider, $output->contents(self::ANSWER) ?? '', fn (string $text): string => $this->withoutLogin($request, $text));
    }

    private function answered(ReviewJobRequest $request, JobOutput $output): ReviewJobAnswer
    {
        $provider = $request->model->provider;
        $status = $this->cliStatusIn($request, $output) ?? throw ReviewJobFailure::withoutCliExit($provider, $this->usageIn($request, $output));

        if (0 !== $status) {
            throw ReviewJobFailure::cliFailed($provider, $status, $this->usageIn($request, $output));
        }

        return ReviewJobAnswer::read($provider, $output->contents(self::ANSWER) ?? '', fn (string $text): string => $this->withoutLogin($request, $text));
    }

    // The script refuses before the CLI runs, so the log holds only its own lines and git's.
    private function refusalIn(ReviewJobRequest $request, JobOutput $output): ?string
    {
        $refusals = array_filter(explode("\n", $this->withoutLogin($request, $output->contents(self::LOG) ?? '')), static fn (string $line): bool => str_starts_with($line, self::REFUSAL));
        $last = array_last($refusals);

        return null === $last ? null : substr($last, strlen(self::REFUSAL));
    }

    // The last such line, because the script writes it after the CLI ended, and the CLI's own errors in the log come before it.
    private function cliStatusIn(ReviewJobRequest $request, JobOutput $output): ?int
    {
        preg_match_all('/^'.preg_quote($request->model->provider->value, '/').' exited with (\d+)$/m', $this->withoutLogin($request, $output->contents(self::LOG) ?? ''), $exits);
        $status = array_last($exits[1]);

        return null === $status ? null : (int) $status;
    }

    // Only the login's value, not the other shapes of a log line, because a cut header in the answer could take the verdict with it; they are redacted where the verdict is stored.
    private function withoutLogin(ReviewJobRequest $request, string $text): string
    {
        $login = $request->login;
        if (!$login instanceof AccountLogin) {
            return $text;
        }
        $spans = new HeldCredentialSpans()->in($text, $login->token);

        // A failed match would leave the rest unredacted, so nothing of the text goes on.
        return $spans instanceof RedactedSpans ? $spans->replacedIn($text) : Redaction::MARKER;
    }
}
