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
use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildCloneIsNoFolder;
use Intakt\Core\Build\UseCase\RunBuildJob\BuilderNotOnClaudeCode;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildJob;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildJobOutcome;
use Intakt\Core\Build\UseCase\RunBuildJob\RunBuildJob;
use Intakt\Core\Git\Contract\BuildCheckout;
use Intakt\Core\Git\Contract\BuildCheckoutFailed;
use Intakt\Core\HandBack\Contract\AcceptedHandBack;
use Intakt\Core\Job\Contract\DockerEngineFailed;
use Intakt\Core\Job\Contract\JobEventNotRecorded;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Plugin\Forge\Contract\ChangeText;
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
        private PrePushCheck $prePushChecks,
        private PushStep $pushes,
        private ParkedTasks $parkedTasks,
        private Ledger $ledger,
        private PushRemoteCheck $remoteCheck,
    ) {
    }

    /**
     * @throws BuildStepNotRecorded
     * @throws PushRemoteRefused
     */
    #[NoDiscard('the outcome says which step the run stopped at')]
    public function run(BuildTaskRequest $request): BuildTaskOutcome
    {
        // Before anything is read, recorded or pushed, because a remote outside the configured repository makes every later step act on two repositories.
        $refusal = $this->remoteCheck->failureOf($request);

        if (null !== $refusal) {
            throw PushRemoteRefused::because($refusal);
        }

        $task = $this->readTask($request);

        if (!$task instanceof ListedIssue) {
            $this->record($request, BuildStep::Task, $task);

            return new BuildTaskOutcome(BuildStep::Task, $task, null);
        }

        return $this->runSteps($request, $task);
    }

    // The failure's text when the task cannot be read, because the caller names the issue and no other issue may stand in for it.
    private function readTask(BuildTaskRequest $request): ListedIssue|string
    {
        try {
            $issue = $this->issues->readOpen($request->project, $request->issue);
        } catch (ForgeReadFailed|ForgeRateLimited $failure) {
            return $failure->getMessage();
        }

        return $issue ?? sprintf('project "%s" has no open issue %d', $request->project, $request->issue);
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

    /**
     * @throws BuildStepNotRecorded
     */
    private function runSteps(BuildTaskRequest $request, ListedIssue $task): BuildTaskOutcome
    {
        $results = [];

        foreach (BuildStep::cases() as $step) {
            $result = match ($step) {
                // Read before the other steps, because they need the task it returns.
                BuildStep::Task => null,
                BuildStep::Mirror => $this->updateMirror($request),
                BuildStep::Clone => $this->makeClone($request),
                BuildStep::BuildJob => $this->runBuildJob($request, $task),
                BuildStep::HandBackCheck => $this->handBacks->check($request, $this->bundleIn($results)),
                // The mirror again first, because main can move while the build job runs and the check merges the branch with current main.
                BuildStep::PrePushCheck => $this->updateMirror($request) ?? $this->prePushChecks->failureOf($request, $this->bundleIn($results)),
                BuildStep::Push => $this->push($request, $results),
            };

            // A local of this run, never a property, because a worker keeps the service between runs.
            $results[$step->value] = $result;

            if ($result instanceof BuildJobOutcome && $result->question instanceof BuildQuestion) {
                return $this->park($request, $task, $result->question);
            }

            $failure = $this->failureIn($result);

            $this->record($request, $step, $failure);

            if (null !== $failure) {
                return new BuildTaskOutcome($step, $failure, $task);
            }
        }

        return new BuildTaskOutcome(array_last(BuildStep::cases()), null, $task);
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

    // The outcome of a job that built, for the hand-back check, or of one that asked an accepted question, for the parking; otherwise the failure's text.
    private function runBuildJob(BuildTaskRequest $request, ListedIssue $task): BuildJobOutcome|string
    {
        try {
            $outcome = $this->builds->run($this->jobOf($request, $task));
        } catch (BuildRoutingNotConfigured|TierResolutionFailure|BuilderNotOnClaudeCode|BuildCloneIsNoFolder|InvalidArgumentException|DockerEngineFailed|JobEventNotRecorded|IOException $failure) {
            return $failure->getMessage();
        }

        // What the job hands back stays untrusted until the hand-back check, so only its verdict reaches the ledger.
        return $outcome->built() || $outcome->question instanceof BuildQuestion ? $outcome : $outcome->verdict->reason();
    }

    /**
     * @param array<string, BuildJobOutcome|AcceptedHandBack|string|null> $results
     */
    private function bundleIn(array $results): string
    {
        // Never null, because the checks run only after a build job that built, and such a job always hands back a bundle.
        return $this->builtIn($results)->bundle ?? throw new LogicException('the checks run only after a build job that handed back a bundle');
    }

    /**
     * @param array<string, BuildJobOutcome|AcceptedHandBack|string|null> $results
     */
    private function builtIn(array $results): BuildJobOutcome
    {
        $built = $results[BuildStep::BuildJob->value] ?? null;

        return $built instanceof BuildJobOutcome ? $built : throw new LogicException('the checks and the push run only after a build job that built');
    }

    /**
     * @param array<string, BuildJobOutcome|AcceptedHandBack|string|null> $results
     */
    private function push(BuildTaskRequest $request, array $results): ?string
    {
        $accepted = $results[BuildStep::HandBackCheck->value] ?? null;
        $text = $this->builtIn($results)->pullRequestText;

        // Never otherwise, because the push runs only after a hand-back check that accepted the head, and a build job that built always hands back its text.
        if (!$accepted instanceof AcceptedHandBack || null === $text) {
            throw new LogicException('the push runs only after an accepted hand-back of a build job that handed back its text');
        }

        $changeText = $this->changeTextOf($text);

        return $changeText instanceof ChangeText ? $this->pushes->failureOf($request, $accepted, $changeText) : $changeText;
    }

    // The run ends at the build job without recording its step, because park() records it as asked in the transaction that stores the question.
    private function park(BuildTaskRequest $request, ListedIssue $task, BuildQuestion $question): BuildTaskOutcome
    {
        try {
            $this->parkedTasks->park($request->project, $request->issue, $question);
        } catch (TaskNotParked $notParked) {
            $this->record($request, BuildStep::BuildJob, $notParked->getMessage());

            return new BuildTaskOutcome(BuildStep::BuildJob, $notParked->getMessage(), $task);
        }

        return new BuildTaskOutcome(BuildStep::BuildJob, null, $task);
    }

    // A job that built and a hand-back the check accepted give their result instead of a failure, so their step passed.
    private function failureIn(BuildJobOutcome|AcceptedHandBack|string|null $result): ?string
    {
        return is_string($result) ? $result : null;
    }

    // The title on the first line and the body after it, as BuilderPrompt asks the builder to write them; fixed words for a text without a title, because the forge opens no pull request without one.
    private function changeTextOf(string $text): ChangeText|string
    {
        [$title, $body] = [...explode("\n", $text, 2), ''];

        return '' === trim($title) ? 'the pull request text has no title on its first line' : new ChangeText(trim($title), trim($body));
    }

    private function jobOf(BuildTaskRequest $request, ListedIssue $task): BuildJob
    {
        // No paths and no setback, because a first build has no file forecast and no earlier round.
        $choice = $this->routing->tierFor($request->project, BuildFacts::fromLabels($task->labels, []));
        $brief = new BuildBrief($task->title."\n\n".$task->body, [], null);

        return new BuildJob($request->project, $request->directory, $request->branch, $brief, $choice->tier, $request->image);
    }
}
