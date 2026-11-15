<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Intakt\Core\Ai\Contract\TierResolutionFailure;
use Intakt\Core\Build\Contract\BuildBrief;
use Intakt\Core\Build\Contract\BuildFacts;
use Intakt\Core\Build\Contract\BuildRouting;
use Intakt\Core\Build\Contract\BuildRoutingNotConfigured;
use Intakt\Core\Build\Contract\BuildStep;
use Intakt\Core\Build\Contract\BuildStepOutcome;
use Intakt\Core\Build\UseCase\RunBuildJob\BuilderNotOnClaudeCode;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildJob;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildJobOutcome;
use Intakt\Core\Build\UseCase\RunBuildJob\RunBuildJob;
use Intakt\Core\Git\Contract\BuildCheckout;
use Intakt\Core\Git\Contract\BuildCheckoutFailed;
use Intakt\Core\Job\Contract\DockerEngineFailed;
use Intakt\Core\Job\Contract\JobEventNotRecorded;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use InvalidArgumentException;
use LogicException;
use NoDiscard;
use Symfony\Component\Filesystem\Exception\IOException;

// Because the build lane calls this for one task at a time, a run stops at the first failed step and never retries it: sending a failure back to the builder is the next task.
final readonly class BuildTask
{
    public function __construct(
        private IssueReads $issues,
        private BuildCheckout $checkout,
        private BuildRouting $routing,
        private RunBuildJob $builds,
        private HandBackCheck $handBacks,
        private Ledger $ledger,
    ) {
    }

    /**
     * @throws BuildStepNotRecorded
     */
    #[NoDiscard('the outcome says which step the run stopped at')]
    public function run(BuildTaskRequest $request): BuildTaskOutcome
    {
        $task = $this->readTask($request);

        if (!$task instanceof ListedIssue) {
            $this->record($request, BuildStep::Task, $task);

            return new BuildTaskOutcome(BuildStep::Task, $task, null);
        }

        return $this->runSteps($request, $task);
    }

    /**
     * @throws BuildStepNotRecorded
     */
    private function runSteps(BuildTaskRequest $request, ListedIssue $task): BuildTaskOutcome
    {
        $bundle = null;

        foreach (BuildStep::cases() as $step) {
            $result = match ($step) {
                // Read before the other steps, because they need the task it returns.
                BuildStep::Task => null,
                BuildStep::Mirror => $this->updateMirror($request),
                BuildStep::Clone => $this->makeClone($request),
                BuildStep::BuildJob => $this->runBuildJob($request, $task),
                BuildStep::HandBackCheck => $this->checkHandBack($request, $bundle),
            };

            // A local of this run, never a property, because a worker keeps the service between runs.
            if ($result instanceof BuildJobOutcome) {
                $bundle = $result->bundle;
            }

            $failure = $this->failureIn($result);

            $this->record($request, $step, $failure);

            if (null !== $failure) {
                return new BuildTaskOutcome($step, $failure, $task);
            }
        }

        return new BuildTaskOutcome(array_last(BuildStep::cases()), null, $task);
    }

    // A job that built hands back its outcome instead of a failure, so its step passed.
    private function failureIn(BuildJobOutcome|string|null $result): ?string
    {
        return $result instanceof BuildJobOutcome ? null : $result;
    }

    // The failure's text when the task cannot be read, because the caller names the issue and no other issue may stand in for it.
    private function readTask(BuildTaskRequest $request): ListedIssue|string
    {
        try {
            $listing = $this->issues->listOpen($request->project, null);
        } catch (ForgeReadFailed|ForgeRateLimited $failure) {
            return $failure->getMessage();
        }

        foreach ($listing->issues() as $issue) {
            // Because GitHub lists pull requests among the issues, and a pull request is no task.
            if ($issue->number === $request->issue && !$issue->isPullRequest) {
                return $issue;
            }
        }

        return sprintf('project "%s" has no open issue %d', $request->project, $request->issue);
    }

    private function updateMirror(BuildTaskRequest $request): ?string
    {
        try {
            $this->checkout->updateMirror($request->project, $request->remote);
        } catch (BuildCheckoutFailed $failure) {
            return $failure->getMessage();
        }

        return null;
    }

    private function makeClone(BuildTaskRequest $request): ?string
    {
        try {
            $this->checkout->makeClone($request->project, $request->branch, $request->directory);
        } catch (BuildCheckoutFailed $failure) {
            return $failure->getMessage();
        }

        return null;
    }

    // The outcome of a job that built, for the hand-back check, otherwise the failure's text.
    private function runBuildJob(BuildTaskRequest $request, ListedIssue $task): BuildJobOutcome|string
    {
        try {
            $outcome = $this->builds->run($this->jobOf($request, $task));
        } catch (BuildRoutingNotConfigured|TierResolutionFailure|BuilderNotOnClaudeCode|InvalidArgumentException|DockerEngineFailed|JobEventNotRecorded|IOException $failure) {
            return $failure->getMessage();
        }

        // What the job hands back stays untrusted until the hand-back check, so only its verdict reaches the ledger.
        return $outcome->built() ? $outcome : $outcome->verdict->reason();
    }

    private function checkHandBack(BuildTaskRequest $request, ?string $bundle): ?string
    {
        // Never null, because the check runs only after a build job that built, and such a job always hands back a bundle.
        return $this->handBacks->failureOf($request, $bundle ?? throw new LogicException('the hand-back check runs only after a build job that handed back a bundle'));
    }

    private function jobOf(BuildTaskRequest $request, ListedIssue $task): BuildJob
    {
        // No paths and no setback, because a first build has no file forecast and no earlier round.
        $choice = $this->routing->tierFor($request->project, BuildFacts::fromLabels($task->labels, []));
        $brief = new BuildBrief($task->title."\n\n".$task->body, [], null);

        return new BuildJob($request->project, $request->directory, $request->branch, $brief, $choice->tier, $request->image);
    }

    private function record(BuildTaskRequest $request, BuildStep $step, ?string $failure): void
    {
        // Because the title and body are untrusted text, the payload names the task by its number only.
        $payload = json_encode([
            'project' => $request->project,
            'task' => (string) $request->issue,
            'step' => $step->value,
            'outcome' => (null === $failure ? BuildStepOutcome::Passed : BuildStepOutcome::Failed)->value,
            'failure' => $failure,
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);

        $result = $this->ledger->append(new LedgerEvent(LedgerEventKind::BuildStepEnded, $payload));

        if (!$result->succeeded()) {
            throw BuildStepNotRecorded::for($request, $step, (string) $result->failure);
        }
    }
}
