<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\SizeTask;

use Intakt\Core\Build\Contract\SizingRequest;
use Intakt\Core\Build\Contract\SizingVerdict;
use Intakt\Core\Build\Domain\SplitPart;
use Intakt\Core\Build\UseCase\BuildTask\BuildRun;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\KeyedComment;
use Intakt\Plugin\GitHubForge\Contract\TaskComments;
use Intakt\Plugin\GitHubForge\Contract\TaskParts;
use NoDiscard;

// The plan is on record before any part exists, and every step after it is found again on a retry, so a split interrupted anywhere ends with each part filed once: docs/design/05-architecture/03-choosing-work.md
final readonly class SplitTask
{
    public function __construct(
        private Ledger $ledger,
        private TaskParts $parts,
        private TaskComments $comments,
    ) {
    }

    // The first plan of the task, whatever its text, because a task is split once: an edited original or a later sizing never plans a second split.
    /**
     * @throws TaskNotSized
     */
    public function planOf(SizingRequest $request): ?SplitPlan
    {
        $recorded = array_first([...$this->ledger->readOfKindAbout(LedgerEventKind::TaskSplitPlanned, SizingRecords::keyOf($request->project, $request->task))]);

        if (null === $recorded) {
            return null;
        }

        return SplitPlan::in($recorded) ?? throw TaskNotSized::planUnreadable($request->project, $request->task);
    }

    // Read back after the append, so the parts are filed from the plan as recorded, with the moment the ledger gave it.
    /**
     * @throws TaskNotSized
     */
    public function planned(BuildRun $run, SizingRequest $request, SizingVerdict $verdict): SplitPlan
    {
        $this->appended(LedgerEventKind::TaskSplitPlanned, $request->project, $request->task, SplitPlan::payloadOf($request, $verdict, $run->id));

        return $this->planOf($request) ?? throw TaskNotSized::notRecorded($request->project, $request->task, 'the split plan is not on record after its append');
    }

    /**
     * @throws TaskNotSized
     */
    #[NoDiscard('the outcome says whether this run split the task')]
    public function finished(SplitPlan $plan): SizingOutcome
    {
        if ([] !== [...$this->ledger->readOfKindAbout(LedgerEventKind::TaskSplit, SizingRecords::keyOf($plan->project, $plan->task))]) {
            return SizingOutcome::AlreadySized;
        }

        $filed = $this->filedInOrder($plan);
        $this->umbrellaNamed($plan, $filed);
        $this->appended(LedgerEventKind::TaskSplit, $plan->project, $plan->task, json_encode(['project' => $plan->project, 'task' => (string) $plan->task, 'run' => $plan->run, 'parts' => array_values($filed)], JSON_THROW_ON_ERROR));

        return SizingOutcome::Split;
    }

    /**
     * @throws TaskNotSized
     */
    private function appended(LedgerEventKind $kind, string $project, int $task, string $payload): void
    {
        $appended = $this->ledger->append(new LedgerEvent($kind, $payload, SizingRecords::keyOf($project, $task)));

        if (!$appended->succeeded()) {
            throw TaskNotSized::notRecorded($project, $task, (string) $appended->failure);
        }
    }

    // In the plan's order, because a later part's `Waits for` lines need the numbers of the earlier parts it depends on.
    /**
     * @return array<int, int>
     *
     * @throws TaskNotSized
     */
    private function filedInOrder(SplitPlan $plan): array
    {
        $filed = [];

        foreach ($plan->parts() as $part) {
            $filed[$part->number] = $this->filed($plan, $part, $filed);
        }

        return $filed;
    }

    /**
     * @param array<int, int> $filed
     *
     * @throws TaskNotSized
     */
    private function filed(SplitPlan $plan, SplitPart $part, array $filed): int
    {
        try {
            return $this->parts->fileOnce($plan->project, $plan->issueOf($part, $filed));
        } catch (ForgeReadFailed|ForgeWriteFailed|ForgeRateLimited $failure) {
            throw TaskNotSized::notSplit($plan->project, $plan->task, sprintf('part %d was not filed: %s', $part->number, $failure->getMessage()));
        }
    }

    // The original stays open and names its parts, because it is their umbrella; keyed by the task, so a retry posts no second comment.
    /**
     * @param array<int, int> $filed
     *
     * @throws TaskNotSized
     */
    private function umbrellaNamed(SplitPlan $plan, array $filed): void
    {
        $parts = implode(', ', array_map(static fn (int $number): string => '#'.$number, $filed));
        $comment = new KeyedComment(sprintf('This task was split into %s. It stays open as their umbrella.', $parts), 'split-'.SizingRecords::keyOf($plan->project, $plan->task));

        try {
            $this->comments->postOnce($plan->project, $plan->task, $comment);
        } catch (ForgeReadFailed|ForgeWriteFailed|ForgeRateLimited $failure) {
            throw TaskNotSized::notSplit($plan->project, $plan->task, 'the umbrella comment was not posted: '.$failure->getMessage());
        }
    }
}
