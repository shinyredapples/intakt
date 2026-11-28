<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\SizeTask;

use Intakt\Core\Build\Contract\ReadinessGap;
use Intakt\Core\Build\Contract\SizingRequest;
use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\UseCase\BuildTask\BuildRun;
use Intakt\Core\Build\UseCase\BuildTask\ParkedTaskNotCleared;
use Intakt\Core\Build\UseCase\BuildTask\ParkedTasks;
use Intakt\Core\Build\UseCase\BuildTask\TaskNotParked;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\KeyedComment;
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

        $latest = $this->records->latestVerdictOf($request);

        if ($latest?->isOf($request) ?? false) {
            return SizingOutcome::AlreadySized;
        }

        $verdict = $this->vendor->size($request);
        $outcome = SizingOutcome::of($verdict);
        $missing = $verdict->missing ?? [];

        if (SizingOutcome::Parked === $outcome && [] !== $missing) {
            $this->parkedAndAsked($run, $request, $missing);
        }

        if (SizingOutcome::Ready === $outcome) {
            $this->releasedFromItsReadinessQuestion($run, $latest);
        }

        // After the parking and the comment, so a record says parked only once the task is parked and asked, and ready only once the earlier readiness question is gone.
        $this->records->record($request, $verdict, $run->id);

        return $outcome;
    }

    // Parked before the comment, because a comment before a failed park would ask about a task that is not parked. The comment is keyed by the hash of the sized text, because the forge can store a comment and still answer as failed: the retry then finds it and posts no second one, while an edited text that still lacks the same item gets its own comment.
    /**
     * @param non-empty-list<ReadinessGap> $missing
     *
     * @throws TaskNotSized
     */
    private function parkedAndAsked(BuildRun $run, SizingRequest $request, array $missing): void
    {
        $question = BuildQuestion::forMissingReadiness($missing);

        try {
            $this->parkedTasks->park($run, $question);
        } catch (TaskNotParked) {
            throw TaskNotSized::notParked($run->project, $run->task);
        }

        try {
            $this->comments->postOnce($run->project, $run->task, new KeyedComment($question->asComment(), 'sizing-'.SizingRecords::textOf($request)));
        } catch (ForgeReadFailed|ForgeWriteFailed|ForgeRateLimited $failure) {
            throw TaskNotSized::notCommented($run->project, $run->task, $failure->getMessage());
        }
    }

    // Cleared as the run that parked it, because a question a build run stored later then stays: an edit that makes the task ready answers the readiness question and no other.
    /**
     * @throws TaskNotSized
     */
    private function releasedFromItsReadinessQuestion(BuildRun $run, ?RecordedSizing $latest): void
    {
        $parkedBy = $latest?->parkedBy();

        if (null === $parkedBy) {
            return;
        }

        try {
            $this->parkedTasks->clear(new BuildRun($run->project, $run->task, $parkedBy));
        } catch (ParkedTaskNotCleared) {
            throw TaskNotSized::stillParked($run->project, $run->task);
        }
    }
}
