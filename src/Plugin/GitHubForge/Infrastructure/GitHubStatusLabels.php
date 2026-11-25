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
use Intakt\Plugin\GitHubForge\Contract\InstallationDoesNotMatchConfiguration;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;
use Intakt\Plugin\GitHubForge\Domain\LabelEvent;
use Intakt\Plugin\GitHubForge\Domain\StatusLabelPlan;
use Intakt\Plugin\GitHubForge\Domain\TaskIssue;
use JsonException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

// Read from the event list, because only it names who applied or removed a label, and a person's change is what gets restored.
final readonly class GitHubStatusLabels implements StatusLabels
{
    private const int PAGE_SIZE = 100;
    private const int MAX_PAGES = 10;
    // About ten seconds in all, because a holder only reads the events and makes a few writes, so a longer hold means it is stuck.
    private const int WAIT_TRIES = 20;
    private const float WAIT_PAUSE_SECONDS = 0.5;
    // Only status-label events are kept, and their list only grows, so a second read of the same length confirms the plan; a status written past the wait meanwhile makes it longer, and the plan is made again from it instead of writing an older status over the newer one. Other labels never count, so a busy pull request cannot use up the reads.
    private const int CONFIRM_READS = 3;
    private const string RESTORED = 'A person changed the status label on this change. Intakt sets it from the state of the review and put it back, so it always shows where the review stands.';

    public function __construct(
        private GitHubCalls $calls,
        private CheckedInstallationTokens $tokens,
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
        // A mirror keeps its own status against another writer's, because it states the round as it is now.
        $toward = static fn (): RoundStatusLabel => $status;
        if (!$this->whileHeld($this->lockOf($address), self::WAIT_TRIES, fn () => $this->write($project, $address, fn (array $events): StatusLabelPlan => StatusLabelPlan::of($status, $events, $this->isOwn(...)), $toward))) {
            $this->write($project, $address, fn (array $events): StatusLabelPlan => StatusLabelPlan::of($status, $events, $this->isOwn(...))->withoutComment(), $toward);
        }
    }

    // Not waited for, because the busy holder reads the events after the person's change and leaves the label as Intakt set it.
    public function restore(string $project, ChangeId $change): void
    {
        $address = $this->addressOf($project, $change);
        $this->whileHeld($this->lockOf($address), 1, fn () => $this->write($project, $address, fn (array $events): ?StatusLabelPlan => StatusLabelPlan::restoring($events, $this->isOwn(...)), $this->givingWay(...)));
    }

    // A restoration gives way to a status another writer set meanwhile, because it only puts back an older one.
    /**
     * @param list<LabelEvent> $others
     */
    private function givingWay(array $others, RoundStatusLabel $restored): RoundStatusLabel
    {
        $set = array_last(array_filter($others, static fn (LabelEvent $event): bool => $event->applied && RoundStatusLabel::tryFrom($event->label) instanceof RoundStatusLabel));

        return $set instanceof LabelEvent ? RoundStatusLabel::from($set->label) : $restored;
    }

    // Planned and written under one lock, because two writers that read the same events would both find the person's change last and both comment; keyed by the pull request in lower case, because GitHub reads its owner and repository so and two spellings name one pull request.
    private function lockOf(PullRequestAddress $address): LockInterface
    {
        return $this->locks->createLock(GitHubForgeServices::STATUS_LABEL_LOCK_PREFIX.hash('sha256', json_encode([strtolower($address->owner), strtolower($address->repository), $address->number], JSON_THROW_ON_ERROR)));
    }

    private function isOwn(string $login): bool
    {
        try {
            return $this->identities->isOwn($login);
        } catch (TrustNotChecked $unchecked) {
            throw new ForgeWriteFailed($unchecked->getMessage(), $unchecked->getCode(), previous: $unchecked);
        }
    }

    /**
     * @param Closure(list<LabelEvent>): ?StatusLabelPlan                   $plan
     * @param Closure(list<LabelEvent>, RoundStatusLabel): RoundStatusLabel $toward
     *
     * @throws ForgeReadFailed
     * @throws ForgeWriteFailed
     */
    private function write(string $project, PullRequestAddress $address, Closure $plan, Closure $toward): void
    {
        $events = $this->eventsOf($address->issuePath());
        for ($read = 1; $read <= self::CONFIRM_READS; ++$read) {
            $planned = $plan($events);
            if (!$planned instanceof StatusLabelPlan) {
                return;
            }

            $current = $this->eventsOf($address->issuePath());
            if (count($current) === count($events)) {
                $this->apply($project, $address, $planned);
                $this->settle($project, $address, $planned, count($current), $toward);

                return;
            }
            $events = $current;
        }

        throw new ForgeWriteFailed('The labels of the change kept changing while Intakt planned its status label.');
    }

    // Read once more after the write, because a writer past the wait can land between the last read and the write, and only the list after both writes shows the two; each writer reads after its own write, so at least one of them sees the other's.
    /**
     * @param Closure(list<LabelEvent>, RoundStatusLabel): RoundStatusLabel $toward
     *
     * @throws ForgeReadFailed
     * @throws ForgeWriteFailed
     */
    private function settle(string $project, PullRequestAddress $address, StatusLabelPlan $written, int $before, Closure $toward): void
    {
        $after = $this->eventsOf($address->issuePath());
        $others = $this->othersWrites(array_slice($after, $before), $written);
        if ([] !== $others) {
            $this->apply($project, $address, StatusLabelPlan::of($toward($others, $written->wanted), $after, $this->isOwn(...))->withoutComment());
        }
    }

    // Intakt's own events after the read that this write did not make, matched one for one, so a status another writer set meanwhile stands out.
    /**
     * @param list<LabelEvent> $since
     *
     * @return list<LabelEvent>
     *
     * @throws ForgeWriteFailed
     */
    private function othersWrites(array $since, StatusLabelPlan $written): array
    {
        $mine = array_map(static fn (string $label): array => [$label, false], $written->remove);
        if ($written->add) {
            $mine[] = [$written->wanted->value, true];
        }

        $others = [];
        foreach ($since as $event) {
            if (!$this->isOwn($event->actor)) {
                continue;
            }
            $match = array_search([$event->label, $event->applied], $mine, true);
            if (false === $match) {
                $others[] = $event;
            } else {
                unset($mine[$match]);
            }
        }

        return $others;
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

    /**
     * @return list<LabelEvent>
     *
     * @throws ForgeReadFailed
     */
    private function eventsOf(string $path): array
    {
        $events = [];
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $listed = $this->page($path.'/events', $page);
            foreach ($listed as $event) {
                $read = $this->labelEvent($event);
                if ($read instanceof LabelEvent && str_starts_with($read->label, RoundStatusLabel::FAMILY_PREFIX)) {
                    $events[] = $read;
                }
            }
            if (count($listed) < self::PAGE_SIZE) {
                return $events;
            }
        }

        throw new ForgeReadFailed('The change holds more events than this forge reads.');
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

    /**
     * @return list<array<mixed>>
     *
     * @throws ForgeReadFailed
     */
    private function page(string $path, int $page): array
    {
        $options = ['auth_bearer' => $this->bearer(), 'query' => ['per_page' => self::PAGE_SIZE, 'page' => $page], 'headers' => ['Accept' => 'application/vnd.github+json']];

        try {
            $response = $this->calls->asInstallation('GET', $path, $options);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ExceptionInterface $failure) {
            throw new ForgeReadFailed(sprintf('GitHub could not be reached (%s).', $failure::class), $failure->getCode(), $failure);
        }

        if ($status < 200 || $status >= 300) {
            throw new ForgeReadFailed(sprintf('GitHub answered %d to a read of the events.', $status));
        }

        try {
            $events = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw new ForgeReadFailed('GitHub listed the events as something other than JSON.', $invalid->getCode(), previous: $invalid);
        }

        if (!is_array($events) || !array_is_list($events)) {
            throw new ForgeReadFailed('GitHub answered an event listing that is not a list.');
        }

        return array_map(static fn (mixed $event): array => is_array($event) ? $event : throw new ForgeReadFailed('GitHub listed an event that is not an object.'), $events);
    }

    /**
     * @param array<mixed> $event
     *
     * @throws ForgeReadFailed
     */
    private function labelEvent(array $event): ?LabelEvent
    {
        $kind = $event['event'] ?? null;
        if ('labeled' !== $kind && 'unlabeled' !== $kind) {
            return null;
        }

        $label = $event['label'] ?? null;
        $actor = $event['actor'] ?? null;
        $name = is_array($label) ? ($label['name'] ?? null) : null;
        $login = is_array($actor) ? ($actor['login'] ?? null) : null;
        if (!is_string($name) || '' === $name || !is_string($login) || '' === $login) {
            throw new ForgeReadFailed('GitHub listed a label event without its label or actor.');
        }

        return new LabelEvent($name, $login, 'labeled' === $kind);
    }

    /**
     * @throws ForgeReadFailed
     */
    private function bearer(): string
    {
        try {
            return $this->tokens->current()->value;
        } catch (InstallationTokenNotMinted|InstallationNotReadBack|InstallationDoesNotMatchConfiguration $refused) {
            throw new ForgeReadFailed('No GitHub read of the events: '.$refused->getMessage(), $refused->getCode(), previous: $refused);
        }
    }
}
