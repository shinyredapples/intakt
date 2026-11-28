<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\SizeTask;

use Closure;
use Intakt\Core\Build\Contract\SizingRequest;
use Intakt\Core\Watcher\Contract\EligibilityLabels;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueHistories;
use Intakt\Plugin\GitHubForge\Contract\IssueLabels;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\LabelApplication;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Plugin\GitHubForge\Contract\SteeringAccounts;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;
use Intakt\Plugin\GitHubWorkSource\Contract\WorkLabels;

// The rule intake applies, asked again here, because the command names any issue and a stranger's issue must neither spend tokens nor be parked: docs/design/05-architecture/14-threat-model.md
final readonly class EligibleTasks
{
    public function __construct(
        private IssueReads $issues,
        private IssueHistories $histories,
        private SteeringAccounts $accounts,
        private EligibilityLabels $labels,
    ) {
    }

    /**
     * @throws TaskNotSized
     */
    public function requestFor(string $project, int $number): SizingRequest
    {
        try {
            $task = $this->issues->readOpen($project, $number);
            $refusal = $task instanceof ListedIssue ? $this->refusalOf($project, $task) : 'it is not an open issue';
        } catch (ForgeReadFailed|ForgeRateLimited|TrustNotChecked $failure) {
            throw TaskNotSized::unreadable($project, $number, $failure->getMessage());
        }

        if (!$task instanceof ListedIssue || null !== $refusal) {
            throw TaskNotSized::unreadable($project, $number, $refusal ?? 'it is not an open issue');
        }

        return new SizingRequest($project, $number, $task->title, $task->body);
    }

    /**
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     * @throws TrustNotChecked
     */
    private function refusalOf(string $project, ListedIssue $task): ?string
    {
        $label = $this->labels->of($project);
        $steers = $this->accessAskedOncePerAccount($project);

        if (!$task->labels->contains($label)) {
            return sprintf('it lacks the label "%s"', $label);
        }

        if ($task->labels->contains(WorkLabels::IDEA)) {
            return sprintf('it carries the label "%s", which intake leaves out', WorkLabels::IDEA);
        }

        if (!$steers($task->author)) {
            return 'its author has no write access';
        }

        // The history of the text as read, because a body edited since by an account without write access would reach the model unvouched.
        $history = $this->histories->of($project, $task->number, $task->updatedAt);

        // The labels too, as a set, because the history lists every application of a label, also of one removed since, and only the labels it holds now show that the read and the history are of the same issue state.
        if ($history->body !== $task->body || $this->sorted($history->currentLabels) !== $this->sorted($task->labels)) {
            return 'it changed while it was read';
        }

        if (!$steers($history->lastEditor)) {
            return 'its last editor has no write access';
        }

        $vouched = array_any($history->labels, static fn (LabelApplication $applied): bool => $label === $applied->label && $steers($applied->appliedBy));

        return $vouched ? null : sprintf('the label "%s" was not applied by an account with write access', $label);
    }

    /**
     * @return list<string>
     */
    private function sorted(IssueLabels $labels): array
    {
        $names = [...$labels];
        sort($names);

        return $names;
    }

    // Remembered for this read only, because the author is often the last editor and the one who applied the label, and each ask is a forge call.
    /**
     * @return Closure(string): bool
     */
    private function accessAskedOncePerAccount(string $project): Closure
    {
        $answers = [];

        return function (string $account) use ($project, &$answers): bool {
            return $answers[$account] ??= $this->accounts->steers($project, $account);
        };
    }
}
