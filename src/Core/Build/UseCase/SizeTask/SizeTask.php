<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\SizeTask;

use Intakt\Core\Build\Contract\ReadinessGap;
use Intakt\Core\Build\Contract\SizingRequest;
use Intakt\Core\Build\Contract\SizingVerdict;
use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\UseCase\BuildTask\BuildRun;
use Intakt\Core\Build\UseCase\BuildTask\ParkedTasks;
use Intakt\Core\Build\UseCase\BuildTask\TaskNotParked;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Plugin\Vendor\Contract\NoProviderLeft;
use Intakt\Plugin\Vendor\Contract\ProviderFailedTransiently;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Plugin\Vendor\Contract\ProviderRejectedTheKey;
use Intakt\Plugin\Vendor\Contract\ProviderUnreachable;
use Intakt\Plugin\Vendor\Contract\SizingVendor;
use Intakt\Plugin\Vendor\Contract\UsageNotRecorded;
use Intakt\Plugin\Vendor\Contract\VendorAccountBusy;
use Intakt\Plugin\Vendor\Contract\VendorAccountOut;
use NoDiscard;
use Symfony\Component\Uid\Uuid;

// Plain code decides every step, because the model only answers one typed object and a steered answer must not park or split a task by itself.
final readonly class SizeTask
{
    public function __construct(
        private IssueReads $issues,
        private SizingVendor $vendor,
        private ParkedTasks $parkedTasks,
        private Ledger $ledger,
    ) {
    }

    /**
     * @throws TaskNotSized
     * @throws UsageNotRecorded
     * @throws ProviderRateLimited
     * @throws ProviderFailedTransiently
     * @throws VendorAccountOut
     * @throws VendorAccountBusy
     * @throws NoProviderLeft
     * @throws ProviderRejectedTheKey
     * @throws ProviderUnreachable
     */
    #[NoDiscard('the outcome says whether the task was parked')]
    public function run(string $project, int $number): SizingOutcome
    {
        $run = new BuildRun($project, $number, Uuid::v4()->toRfc4122());
        $verdict = $this->vendor->size($project, $this->requestFor($run));
        $outcome = $this->outcomeOf($verdict);

        $this->record($run, $outcome, $verdict);

        return $this->parkedWhenNotReady($run, $outcome, $verdict);
    }

    private function requestFor(BuildRun $run): SizingRequest
    {
        try {
            $task = $this->issues->readOpen($run->project, $run->task);
        } catch (ForgeReadFailed|ForgeRateLimited $failure) {
            throw TaskNotSized::unreadable($run->project, $run->task, $failure->getMessage());
        }

        if (!$task instanceof ListedIssue) {
            throw TaskNotSized::unreadable($run->project, $run->task, 'it is not an open issue');
        }

        return new SizingRequest($run->project, $run->task, $task->title, $task->body);
    }

    private function outcomeOf(?SizingVerdict $verdict): SizingOutcome
    {
        return match (true) {
            !$verdict instanceof SizingVerdict => SizingOutcome::Invalid,
            $verdict->ready => SizingOutcome::Ready,
            default => SizingOutcome::Parked,
        };
    }

    // The task by its number and the verdict's closed fields only, because the title and the model's reason are untrusted text.
    private function record(BuildRun $run, SizingOutcome $outcome, ?SizingVerdict $verdict): void
    {
        $payload = json_encode([
            'project' => $run->project,
            'task' => (string) $run->task,
            'outcome' => $outcome->value,
            'missing' => array_map(static fn (ReadinessGap $gap): string => $gap->value, $verdict->missing ?? []),
            'size' => $verdict?->size->value,
            'estimated_lines' => $verdict?->estimated_lines,
        ], JSON_THROW_ON_ERROR);

        $appended = $this->ledger->append(new LedgerEvent(LedgerEventKind::TaskSized, $payload));

        if (!$appended->succeeded()) {
            throw TaskNotSized::notRecorded($run->project, $run->task, (string) $appended->failure);
        }
    }

    /**
     * @throws TaskNotSized
     */
    private function parkedWhenNotReady(BuildRun $run, SizingOutcome $outcome, ?SizingVerdict $verdict): SizingOutcome
    {
        // An empty list never reaches here, because the agent refuses a verdict that is not ready with nothing missing.
        if (SizingOutcome::Parked !== $outcome || !$verdict instanceof SizingVerdict || [] === $verdict->missing) {
            return $outcome;
        }

        try {
            $this->parkedTasks->park($run, BuildQuestion::forMissingReadiness($verdict->missing));
        } catch (TaskNotParked) {
            throw TaskNotSized::notParked($run->project, $run->task);
        }

        return $outcome;
    }
}
