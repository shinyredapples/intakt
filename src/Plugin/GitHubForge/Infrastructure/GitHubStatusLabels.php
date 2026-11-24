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
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

// Read from the event list, because only it names who applied or removed a label, and a person's change is what gets restored.
final readonly class GitHubStatusLabels implements StatusLabels
{
    private const int PAGE_SIZE = 100;
    private const int MAX_PAGES = 10;
    private const string RESTORED = 'A person changed the status label on this change. Intakt sets it from the state of the review and put it back, so it always shows where the review stands.';

    public function __construct(
        private GitHubCalls $calls,
        private CheckedInstallationTokens $tokens,
        private WatchedRepositories $repositories,
        private SteeringIdentities $identities,
        private GitHubIssueComments $comments,
        private GitHubLabelWrites $writes,
        private LockFactory $locks,
    ) {
    }

    public function mirror(string $project, ChangeId $change, RoundStatusLabel $status): void
    {
        $this->whileHeld($project, $change, fn (array $events): StatusLabelPlan => StatusLabelPlan::of($status, $events, $this->isOwn(...)));
    }

    public function restore(string $project, ChangeId $change): void
    {
        $this->whileHeld($project, $change, fn (array $events): ?StatusLabelPlan => StatusLabelPlan::restoring($events, $this->isOwn(...)));
    }

    // Planned and written under one lock, because two writers that read the same events would both find the person's change last and both comment; not waited for, because the busy writer leaves the label to the one holding it.
    /**
     * @param Closure(list<LabelEvent>): ?StatusLabelPlan $plan
     *
     * @throws ChangeNotFound
     * @throws ForgeReadFailed
     * @throws ForgeWriteFailed
     */
    private function whileHeld(string $project, ChangeId $change, Closure $plan): void
    {
        $address = $this->addressOf($project, $change);
        $lock = $this->locks->createLock(GitHubForgeServices::STATUS_LABEL_LOCK_PREFIX.hash('sha256', json_encode([$project, $change->value], JSON_THROW_ON_ERROR)));
        if (!$lock->acquire()) {
            throw new ForgeWriteFailed('Another write is setting the status label of this change.');
        }

        try {
            $this->apply($project, $address, $plan($this->eventsOf($address->issuePath())));
        } finally {
            $lock->release();
        }
    }

    /**
     * @throws ChangeNotFound
     */
    private function addressOf(string $project, ChangeId $change): PullRequestAddress
    {
        $address = PullRequestAddress::of($change);
        $configured = $this->repositories->addressOf($project);
        if ($address->owner !== $configured->owner() || $address->repository !== $configured->name()) {
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
                if ($read instanceof LabelEvent) {
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

    private function isOwn(string $login): bool
    {
        try {
            return $this->identities->isOwn($login);
        } catch (TrustNotChecked $unchecked) {
            throw new ForgeWriteFailed($unchecked->getMessage(), $unchecked->getCode(), previous: $unchecked);
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
