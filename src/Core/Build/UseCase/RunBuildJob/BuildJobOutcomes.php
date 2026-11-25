<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\Domain\BuildStopCause;
use Intakt\Core\Build\Domain\QuestionRefused;
use Intakt\Core\Job\Contract\JobCause;
use Intakt\Core\Job\Contract\JobOutput;
use Intakt\Core\Job\Contract\JobResult;
use NoDiscard;

// The cause comes from the orchestrator's own watch, never from the output, because the builder writes every file there.
final readonly class BuildJobOutcomes
{
    #[NoDiscard]
    public function of(?JobResult $result, ?BuildStopCause $stopCause): BuildJobOutcome
    {
        $outcome = $this->outcomeOf($result);

        return $stopCause instanceof BuildStopCause ? $outcome->stoppedBy($stopCause) : $outcome;
    }

    private function outcomeOf(?JobResult $result): BuildJobOutcome
    {
        if (!$result instanceof JobResult) {
            return new BuildJobOutcome(BuildVerdict::NoResult);
        }

        $log = $result->output->contents(RunBuildJob::LOG) ?? '';

        return match ($result->end->cause) {
            JobCause::MemoryKilled => new BuildJobOutcome(BuildVerdict::MemoryKilled, log: $log),
            JobCause::TimedOut => new BuildJobOutcome(BuildVerdict::TimedOut, log: $log),
            // The builder cannot set the script's exit code, so the one code that says its files may still be in the output is trusted; what a build hands back is read from the output only.
            JobCause::Exited => RunBuildJob::OUTPUT_NOT_CLEARED === $result->end->exitCode
                ? new BuildJobOutcome(BuildVerdict::NoResult, log: $log)
                : $this->handedBack($result->output, $log),
        };
    }

    private function handedBack(JobOutput $output, string $log): BuildJobOutcome
    {
        $question = $output->contents(RunBuildJob::QUESTION);

        if (null !== $question) {
            return $this->asked($question, $log);
        }

        $bundle = $output->contents(RunBuildJob::BUNDLE);
        $text = $output->contents(RunBuildJob::PULL_REQUEST_TEXT);

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
