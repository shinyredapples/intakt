<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Closure;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\Forge\Contract\RoundStatusLabel;
use Intakt\Plugin\Forge\Contract\StatusLabels;
use Intakt\Plugin\GitHubForge\Contract\GitHubForgeServices;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;
use Intakt\Plugin\GitHubForge\Domain\StatusLabelIntent;
use Intakt\Plugin\GitHubForge\Domain\StatusLabelPlan;
use Intakt\Plugin\GitHubForge\Domain\TaskIssue;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

// Read from the event list, because only it names who applied or removed a label, and a person's change is what gets restored.
final readonly class GitHubStatusLabels implements StatusLabels
{
    // About ten seconds in all, because a holder only reads the events and makes a few writes, so a longer hold means it is stuck.
    private const int WAIT_TRIES = 20;
    private const float WAIT_PAUSE_SECONDS = 0.5;
    // The status events only grow, so a second read of the same length confirms the plan; a status written past the wait meanwhile makes the list longer, and the plan is made again from it instead of writing an older status over the newer one.
    private const int CONFIRM_READS = 3;
    private const string RESTORED = 'A person changed the status label on this change. Intakt sets it from the state of the review and put it back, so it always shows where the review stands.';

    public function __construct(
        private GitHubStatusEvents $events,
        private WatchedRepositories $repositories,
        private SteeringIdentities $identities,
        private GitHubIssueComments $comments,
        private GitHubLabelWrites $writes,
        private LockFactory $locks,
        private ClockInterface $clock,
    ) {
    }

    // Waits a bounded while for a busy lock and then writes without it and without a comment, because a new status that gave up would leave the old one standing (a restoration aims at the status Intakt applied last), and the holder comments on the person's change.
    public function mirror(string $project, ChangeId $change, RoundStatusLabel $status): void
    {
        $address = $this->addressOf($project, $change);
        $intent = StatusLabelIntent::mirroring($status, $this->isOwn(...));
        if (!$this->whileHeld($this->lockOf($address), self::WAIT_TRIES, fn () => $this->write($project, $address, $intent))) {
            $this->write($project, $address, $intent->withoutComment());
        }
    }

    // Not waited for, because the busy holder reads the events after the person's change and leaves the label as Intakt set it.
    public function restore(string $project, ChangeId $change): void
    {
        $address = $this->addressOf($project, $change);
        $this->whileHeld($this->lockOf($address), 1, fn () => $this->write($project, $address, StatusLabelIntent::restoring($this->isOwn(...))));
    }

    /**
     * @throws ChangeNotFound
     */
    private function addressOf(string $project, ChangeId $change): PullRequestAddress
    {
        $address = PullRequestAddress::of($change);
        if (!$address->isIn($this->repositories->addressOf($project))) {
            throw ChangeNotFound::named($change);
        }

        return $address;
    }

    private function isOwn(string $login): bool
    {
        try {
            return $this->identities->isOwn($login);
        } catch (TrustNotChecked $unchecked) {
            throw new ForgeWriteFailed($unchecked->getMessage(), $unchecked->getCode(), previous: $unchecked);
        }
    }

    // Planned and written under one lock, because two writers that read the same events would both find the person's change last and both comment; keyed by the pull request in lower case, because GitHub reads its owner and repository so and two spellings name one pull request.
    private function lockOf(PullRequestAddress $address): LockInterface
    {
        return $this->locks->createLock(GitHubForgeServices::STATUS_LABEL_LOCK_PREFIX.hash('sha256', json_encode([strtolower($address->owner), strtolower($address->repository), $address->number], JSON_THROW_ON_ERROR)));
    }

    // False when the lock stayed busy for every try; tried without blocking and paced by the clock, so the wait has a bound and a lock store that cannot block still serves.
    /**
     * @param Closure(): void $write
     */
    private function whileHeld(LockInterface $lock, int $tries, Closure $write): bool
    {
        for ($try = 1; !$lock->acquire(); ++$try) {
            if ($try >= $tries) {
                return false;
            }
            $this->clock->sleep(self::WAIT_PAUSE_SECONDS);
        }

        try {
            $write();
        } finally {
            $lock->release();
        }

        return true;
    }

    // Read once more after the write, because a writer past the wait can land between the last read and the write; each writer reads after its own write, so at least one of two overlapping writers sees the other's status and settles the label.
    /**
     * @throws ForgeReadFailed
     * @throws ForgeWriteFailed
     */
    private function write(string $project, PullRequestAddress $address, StatusLabelIntent $intent): void
    {
        $events = $this->events->of($address);
        for ($read = 1; $read <= self::CONFIRM_READS; ++$read) {
            $planned = $intent->plan($events);
            if (!$planned instanceof StatusLabelPlan) {
                return;
            }

            $current = $this->events->of($address);
            if (count($current) === count($events)) {
                $this->apply($project, $address, $planned);
                $this->apply($project, $address, $intent->settling($planned, $this->events->of($address), count($current)));

                return;
            }
            $events = $current;
        }

        throw new ForgeWriteFailed('The labels of the change kept changing while Intakt planned its status label.');
    }

    /**
     * @throws ForgeWriteFailed
     */
    private function apply(string $project, PullRequestAddress $address, ?StatusLabelPlan $plan): void
    {
        if (!$plan instanceof StatusLabelPlan) {
            return;
        }

        foreach ($plan->remove as $label) {
            $this->writes->remove($address->issuePath(), $label);
        }
        if ($plan->add) {
            $this->writes->add($address->issuePath(), $plan->wanted->value);
        }
        if ($plan->commentOnRestore) {
            $this->comments->post(new TaskIssue($project, $address->number), self::RESTORED);
        }
    }
}
