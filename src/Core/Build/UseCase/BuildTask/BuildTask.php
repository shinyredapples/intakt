<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Intakt\Core\Build\Contract\BuildStep;
use Intakt\Core\Build\Contract\BuildStepOutcome;
use Intakt\Core\Git\Contract\BuildCheckout;
use Intakt\Core\Git\Contract\BuildCheckoutFailed;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use NoDiscard;

// Because the build lane calls this for one task at a time, a run stops at the first failed step and never retries it: sending a failure back to the builder is the next task.
final readonly class BuildTask
{
    public function __construct(
        private IssueReads $issues,
        private BuildCheckout $checkout,
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

        $this->record($request, BuildStep::Task, null);
        $failure = $this->updateMirror($request);

        if (null !== $failure) {
            $this->record($request, BuildStep::Mirror, $failure);

            return new BuildTaskOutcome(BuildStep::Mirror, $failure, $task);
        }

        $this->record($request, BuildStep::Mirror, null);
        $failure = $this->makeClone($request);

        if (null !== $failure) {
            $this->record($request, BuildStep::Clone, $failure);

            return new BuildTaskOutcome(BuildStep::Clone, $failure, $task);
        }

        $this->record($request, BuildStep::Clone, null);

        return new BuildTaskOutcome(BuildStep::Clone, null, $task);
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
