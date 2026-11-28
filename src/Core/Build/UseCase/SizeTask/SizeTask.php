<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\SizeTask;

use Intakt\Core\Build\Contract\SizingVerdict;
use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\UseCase\BuildTask\BuildRun;
use Intakt\Core\Build\UseCase\BuildTask\ParkedTasks;
use Intakt\Core\Build\UseCase\BuildTask\TaskNotParked;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\TaskComments;
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
use Symfony\Component\Lock\Exception\ExceptionInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Uid\Uuid;

// Plain code decides every step, because the model only answers one typed object and a steered answer must not park or split a task by itself.
final readonly class SizeTask
{
    public function __construct(
        private EligibleTasks $tasks,
        private SizingVendor $vendor,
        private SizingRecords $records,
        private ParkedTasks $parkedTasks,
        private TaskComments $comments,
        private LockFactory $locks,
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
        // Held from the prior-verdict look until the verdict is recorded, so two overlapping runs of one task never both call the model and park it; no TTL, because the call may take minutes and the lock goes with the process.
        $lock = $this->locks->createLock(SizingRecords::keyOf($project, $number), ttl: null);
        $this->takeWithoutWaiting($lock, $project, $number);

        try {
            return $this->sizedOnce(new BuildRun($project, $number, Uuid::v4()->toRfc4122()));
        } finally {
            $lock->release();
        }
    }

    // Without waiting, because the run that holds the lock sizes the task and a second one would only spend the same tokens again.
    /**
     * @throws TaskNotSized
     */
    private function takeWithoutWaiting(LockInterface $lock, string $project, int $number): void
    {
        try {
            $taken = $lock->acquire(blocking: false);
        } catch (ExceptionInterface) {
            $taken = false;
        }

        if (!$taken) {
            throw TaskNotSized::busy($project, $number);
        }
    }

    private function sizedOnce(BuildRun $run): SizingOutcome
    {
        $request = $this->tasks->requestFor($run->project, $run->task);

        if ($this->records->holdsAVerdictOn($request)) {
            return SizingOutcome::AlreadySized;
        }

        $verdict = $this->vendor->size($request);
        $outcome = $this->outcomeOf($verdict);

        $this->parkedWhenNotReady($run, $verdict);
        // After the parking and the comment, so a record says parked only once the task is parked and asked.
        $this->records->record($request, $outcome, $verdict);

        return $outcome;
    }

    private function outcomeOf(?SizingVerdict $verdict): SizingOutcome
    {
        return match (true) {
            !$verdict instanceof SizingVerdict => SizingOutcome::Invalid,
            $verdict->ready => SizingOutcome::Ready,
            default => SizingOutcome::Parked,
        };
    }

    // Parked before the comment, because a retry after a failed comment parks the task again in place and comments once, while a comment before a failed park would be posted twice.
    /**
     * @throws TaskNotSized
     */
    private function parkedWhenNotReady(BuildRun $run, ?SizingVerdict $verdict): void
    {
        // An empty list never reaches here, because the agent refuses a verdict that is not ready with nothing missing.
        if (!$verdict instanceof SizingVerdict || $verdict->ready || [] === $verdict->missing) {
            return;
        }

        $question = BuildQuestion::forMissingReadiness($verdict->missing);

        try {
            $this->parkedTasks->park($run, $question);
        } catch (TaskNotParked) {
            throw TaskNotSized::notParked($run->project, $run->task);
        }

        try {
            $this->comments->post($run->project, $run->task, $question->asComment());
        } catch (ForgeWriteFailed|ForgeRateLimited $failure) {
            throw TaskNotSized::notCommented($run->project, $run->task, $failure->getMessage());
        }
    }
}
