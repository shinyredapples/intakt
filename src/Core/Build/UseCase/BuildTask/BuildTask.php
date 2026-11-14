<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

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

// One run builds one task and stops at the first step that fails; a failed step is recorded, never retried here.
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
            return $this->stoppedAt($request, BuildStep::Task, $task, null);
        }

        $this->record($request, BuildStep::Task, null);
        $failure = $this->updateMirror($request);

        if (null !== $failure) {
            return $this->stoppedAt($request, BuildStep::Mirror, $failure, $task);
        }

        $this->record($request, BuildStep::Mirror, null);
        $failure = $this->makeClone($request);

        if (null !== $failure) {
            return $this->stoppedAt($request, BuildStep::Clone, $failure, $task);
        }

        $this->record($request, BuildStep::Clone, null);

        return new BuildTaskOutcome(BuildStep::Clone, null, $task);
    }

    // The failure's text when the task cannot be read, because the caller names the issue and nothing else may stand in for it.
    private function readTask(BuildTaskRequest $request): ListedIssue|string
    {
        try {
            $listing = $this->issues->listOpen($request->project, null);
        } catch (ForgeReadFailed|ForgeRateLimited $failure) {
            return $failure->getMessage();
        }

        foreach ($listing->issues() as $issue) {
            // GitHub lists pull requests among the issues, and a pull request is no task.
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

    private function stoppedAt(BuildTaskRequest $request, BuildStep $step, string $failure, ?ListedIssue $task): BuildTaskOutcome
    {
        $this->record($request, $step, $failure);

        return new BuildTaskOutcome($step, $failure, $task);
    }

    private function record(BuildTaskRequest $request, BuildStep $step, ?string $failure): void
    {
        // The task is the issue number, never its title or body, which are untrusted text.
        $payload = json_encode([
            'project' => $request->project,
            'task' => (string) $request->issue,
            'step' => $step->value,
            'outcome' => null === $failure ? 'passed' : 'failed',
            'failure' => $failure,
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);

        $result = $this->ledger->append(new LedgerEvent(LedgerEventKind::BuildStepEnded, $payload));

        if (!$result->succeeded()) {
            throw BuildStepNotRecorded::for($request, $step, (string) $result->failure);
        }
    }
}
