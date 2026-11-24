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
use Intakt\Core\Build\Contract\Decisions;
use Intakt\Core\Build\Contract\TaskKind;
use Intakt\Core\Build\Domain\BugReport;
use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildCloneIsNoFolder;
use Intakt\Core\Build\UseCase\RunBuildJob\BuilderNotOnClaudeCode;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildJob;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildJobOutcome;
use Intakt\Core\Build\UseCase\RunBuildJob\RunBuildJob;
use Intakt\Core\Git\Contract\BuildCheckout;
use Intakt\Core\Git\Contract\BuildCheckoutFailed;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\HandBack\Contract\AcceptedHandBack;
use Intakt\Core\Job\Contract\DockerEngineFailed;
use Intakt\Core\Job\Contract\JobEventNotRecorded;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\Labels;
use Intakt\Plugin\Forge\Contract\ChangeText;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use InvalidArgumentException;
use LogicException;
use NoDiscard;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Lock\Exception\ExceptionInterface;
use Symfony\Component\Lock\LockFactory;

// Because the build lane calls this for one task at a time, a run stops at the first failed step and never retries it: sending a failure back to the builder is the next task.
final readonly class BuildTask
{
    // Fixed words, because the message of a lock store's failure can name where the store keeps its locks.
    public const string START_NOT_LOCKED = 'the start of the run could not be locked';

    public const string START_BUSY = 'another run of this task is starting';

    public function __construct(
        private IssueReads $issues,
        private BuildCheckout $checkout,
        private BuildRouting $routing,
        private RunBuildJob $builds,
        private HandBackCheck $handBacks,
        private PrePushCheck $prePushChecks,
        private PullRequestTextCheck $textChecks,
        private PushStep $pushes,
        private ParkedTasks $parkedTasks,
        private Ledger $ledger,
        private PushRemoteCheck $remoteCheck,
        private LockFactory $locks,
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

        $run = BuildRun::startedFor($request);
        $task = $this->startInOrder($run, $request);

        if (!$task instanceof ListedIssue) {
            return new BuildTaskOutcome(BuildStep::Task, $task, null);
        }

        return $this->runSteps($request, $run, $task);
    }

    // Under a lock of the task from the issue read until its row is recorded, because the task page takes the run whose task row comes last in the ledger as the run started last, so a run that started earlier must not record its task row after a later one; taken without waiting, so a stalled holder never blocks the next run, and a run that cannot take it records its failed task row outside the lock and runs no other step, which is right even when the holder records its task row afterwards, because the page then shows the holder, the run that goes on.
    /**
     * @throws BuildStepNotRecorded
     */
    private function startInOrder(BuildRun $run, BuildTaskRequest $request): ListedIssue|string
    {
        $lock = $this->locks->createLock($run->startLockKey(), ttl: null);

        try {
            $failure = $lock->acquire(blocking: false) ? null : self::START_BUSY;
        } catch (ExceptionInterface) {
            $failure = self::START_NOT_LOCKED;
        }

        if (null !== $failure) {
            $this->record($run, BuildStep::Task, $failure);

            return $failure;
        }

        try {
            $task = $this->readTask($request);
            $this->record($run, BuildStep::Task, $task instanceof ListedIssue ? null : $task);

            return $task;
        } finally {
            $lock->release();
        }
    }

    private function record(BuildRun $run, BuildStep $step, ?string $failure): void
    {
        $payload = $run->stepPayload($step, null === $failure ? BuildStepOutcome::Passed : BuildStepOutcome::Failed, $failure);
        $result = $this->ledger->append(new LedgerEvent(LedgerEventKind::BuildStepEnded, $payload));

        if (!$result->succeeded()) {
            throw BuildStepNotRecorded::for($run, $step, (string) $result->failure);
        }
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

    /**
     * @throws BuildStepNotRecorded
     */
    private function runSteps(BuildTaskRequest $request, BuildRun $run, ListedIssue $task): BuildTaskOutcome
    {
        $results = [];

        // From the second step on, because startInOrder() reads and records the first, the task, which the other steps need.
        foreach (array_slice(BuildStep::cases(), 1) as $step) {
            $result = match ($step) {
                BuildStep::Mirror => $this->updateMirror($request),
                BuildStep::Clone => $this->makeClone($request),
                BuildStep::BuildJob => $this->runBuildJob($request, $task),
                BuildStep::HandBackCheck => $this->handBacks->check($request, $this->bundleIn($results)),
                // The mirror again first, because main can move while the build job runs and the check merges the branch with current main; the text last, so only a head that would otherwise be pushed counts toward its one go-back.
                BuildStep::PrePushCheck => $this->updateMirror($request) ?? $this->prePushChecks->failureOf($request, $this->bundleIn($results)) ?? $this->textChecks->failureOf($request, $task->body, $this->bodyOf($this->textIn($results))),
                BuildStep::Push => $this->push($request, $results),
            };

            // A local of this run, never a property, because a worker keeps the service between runs.
            $results[$step->value] = $result;

            $question = $this->questionIn($result);

            if ($question instanceof BuildQuestion) {
                return $this->park($run, $task, $question);
            }

            $failure = $this->failureIn($result);

            $this->record($run, $step, $failure);

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
    private function runBuildJob(BuildTaskRequest $request, ListedIssue $task): BuildJobOutcome|BuildQuestion|string
    {
        $incomplete = $this->incompleteBugQuestion($task);

        if ($incomplete instanceof BuildQuestion) {
            return $incomplete;
        }

        try {
            $outcome = $this->builds->run($this->jobOf($request, $task));
        } catch (BuildRoutingNotConfigured|TierResolutionFailure|BuilderNotOnClaudeCode|BuildCloneIsNoFolder|InvalidArgumentException|DockerEngineFailed|JobEventNotRecorded|IOException $failure) {
            return $failure->getMessage();
        }

        // What the job hands back stays untrusted until the hand-back check, so only its verdict reaches the ledger.
        return $outcome->built() || $outcome->question instanceof BuildQuestion ? $outcome : $outcome->verdict->reason();
    }

    /**
     * @param array<string, BuildJobOutcome|BuildQuestion|AcceptedHandBack|string|null> $results
     */
    private function bundleIn(array $results): string
    {
        // Never null, because the checks run only after a build job that built, and such a job always hands back a bundle.
        return $this->builtIn($results)->bundle ?? throw new LogicException('the checks run only after a build job that handed back a bundle');
    }

    /**
     * @param array<string, BuildJobOutcome|BuildQuestion|AcceptedHandBack|string|null> $results
     */
    private function builtIn(array $results): BuildJobOutcome
    {
        $built = $results[BuildStep::BuildJob->value] ?? null;

        return $built instanceof BuildJobOutcome ? $built : throw new LogicException('the checks and the push run only after a build job that built');
    }

    /**
     * @param array<string, BuildJobOutcome|BuildQuestion|AcceptedHandBack|string|null> $results
     */
    private function textIn(array $results): string
    {
        // Never null, because the checks and the push run only after a build job that built, and such a job always hands back its text.
        return $this->builtIn($results)->pullRequestText ?? throw new LogicException('the checks and the push run only after a build job that handed back its text');
    }

    private function bodyOf(string $text): string
    {
        return trim(explode("\n", $text, 2)[1] ?? '');
    }

    /**
     * @param array<string, BuildJobOutcome|BuildQuestion|AcceptedHandBack|string|null> $results
     */
    private function push(BuildTaskRequest $request, array $results): ?string
    {
        $accepted = $results[BuildStep::HandBackCheck->value] ?? null;
        $text = $this->textIn($results);

        // Never otherwise, because the push runs only after a hand-back check that accepted the head.
        if (!$accepted instanceof AcceptedHandBack) {
            throw new LogicException('the push runs only after an accepted hand-back');
        }

        $changeText = $this->changeTextOf($text);

        return $changeText instanceof ChangeText ? $this->pushes->failureOf($request, $accepted, $changeText) : $changeText;
    }

    private function questionIn(BuildJobOutcome|BuildQuestion|AcceptedHandBack|string|null $result): ?BuildQuestion
    {
        return $result instanceof BuildJobOutcome ? $result->question : ($result instanceof BuildQuestion ? $result : null);
    }

    // The run ends at the build job without recording its step, because park() records it as asked in the transaction that stores the question.
    private function park(BuildRun $run, ListedIssue $task, BuildQuestion $question): BuildTaskOutcome
    {
        try {
            $this->parkedTasks->park($run, $question);
        } catch (TaskNotParked $notParked) {
            $this->record($run, BuildStep::BuildJob, $notParked->getMessage());

            return new BuildTaskOutcome(BuildStep::BuildJob, $notParked->getMessage(), $task);
        }

        return new BuildTaskOutcome(BuildStep::BuildJob, null, $task);
    }

    // A job that built and a hand-back the check accepted give their result instead of a failure, so their step passed.
    private function failureIn(BuildJobOutcome|BuildQuestion|AcceptedHandBack|string|null $result): ?string
    {
        return is_string($result) ? $result : null;
    }

    // The title on the first line and the body after it, as BuilderPrompt asks the builder to write them; fixed words for a text without a title, because the forge opens no pull request without one.
    private function changeTextOf(string $text): ChangeText|string
    {
        $title = explode("\n", $text, 2)[0];

        return '' === trim($title) ? 'the pull request text has no title on its first line' : new ChangeText(trim($title), $this->bodyOf($text));
    }

    // Asked before the build job runs, because a bug without its four parts has no failing test to write first and the builder would guess it.
    private function incompleteBugQuestion(ListedIssue $task): ?BuildQuestion
    {
        if (TaskKind::Bug !== TaskKind::fromLabels(new Labels(...$task->labels))) {
            return null;
        }

        $missing = BugReport::fromBody($task->body)->missingParts();

        return [] === $missing ? null : BuildQuestion::forMissingBugParts($missing);
    }

    private function jobOf(BuildTaskRequest $request, ListedIssue $task): BuildJob
    {
        // No paths and no setback, because a first build has no file forecast and no earlier round.
        $choice = $this->routing->tierFor($request->project, BuildFacts::fromLabels(new Labels(...$task->labels), new Paths()));
        $brief = new BuildBrief($task->title."\n\n".$task->body, new Decisions(), null, TaskKind::fromLabels(new Labels(...$task->labels)));

        return new BuildJob($request->project, $request->directory, $request->branch, $brief, $choice->tier, $request->image);
    }
}
