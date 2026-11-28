<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\SizeTask;

use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
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
use Intakt\Plugin\Vendor\Contract\SizingVendor;
use NoDiscard;
use Symfony\Component\Uid\Uuid;

// Plain code decides every step: the model answers one typed object, and only a valid one that says not ready parks the task.
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
     */
    #[NoDiscard('the outcome says whether the task was parked')]
    public function run(string $project, int $number): SizingOutcome
    {
        $request = $this->requestFor($project, $number);
        $verdict = $this->vendor->size($project, $request);
        $outcome = $this->outcomeOf($verdict);

        $this->record($project, $number, $outcome, $verdict);

        return $this->parkedWhenNotReady($project, $number, $outcome, $verdict);
    }

    private function requestFor(string $project, int $number): SizingRequest
    {
        try {
            $task = $this->issues->readOpen($project, $number);
        } catch (ForgeReadFailed|ForgeRateLimited $failure) {
            throw TaskNotSized::unreadable($project, $number, $failure->getMessage());
        }

        if (!$task instanceof ListedIssue) {
            throw TaskNotSized::unreadable($project, $number, 'it is not an open issue');
        }

        return new SizingRequest($project, $number, $task->title, $task->body);
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
    private function record(string $project, int $number, SizingOutcome $outcome, ?SizingVerdict $verdict): void
    {
        $payload = json_encode([
            'project' => $project,
            'task' => (string) $number,
            'outcome' => $outcome->value,
            'missing' => array_map(static fn (ReadinessGap $gap): string => $gap->value, $verdict->missing ?? []),
            'size' => $verdict?->size->value,
            'estimated_lines' => $verdict?->estimated_lines,
        ], JSON_THROW_ON_ERROR);

        $appended = $this->ledger->append(new LedgerEvent(LedgerEventKind::TaskSized, $payload));

        if (!$appended->succeeded()) {
            throw TaskNotSized::notRecorded($project, $number, (string) $appended->failure);
        }
    }

    /**
     * @throws TaskNotSized
     */
    private function parkedWhenNotReady(string $project, int $number, SizingOutcome $outcome, ?SizingVerdict $verdict): SizingOutcome
    {
        // An empty list never reaches here, because the agent refuses a verdict that is not ready with nothing missing.
        if (SizingOutcome::Parked !== $outcome || !$verdict instanceof SizingVerdict || [] === $verdict->missing) {
            return $outcome;
        }

        try {
            $this->parkedTasks->park(new BuildRun($project, $number, Uuid::v4()->toRfc4122()), BuildQuestion::forMissingReadiness($verdict->missing));
        } catch (TaskNotParked) {
            throw TaskNotSized::notParked($project, $number);
        }

        return $outcome;
    }
}
