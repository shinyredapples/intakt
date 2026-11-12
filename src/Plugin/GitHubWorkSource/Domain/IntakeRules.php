<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

use Intakt\Plugin\GitHubWorkSource\Contract\WorkLabels;

// The steps of docs/design/05-architecture/03-choosing-work.md: eligible, then ready, then ordered.
final readonly class IntakeRules
{
    public function __construct(
        private string $eligibilityLabel,
    ) {
    }

    /**
     * @param list<QueuedIssue> $items
     */
    public function planOf(array $items): IntakePlan
    {
        $open = $this->byNumber($items);
        $withPullRequest = $this->closedByOpenPullRequests($items);

        $ready = [];
        $held = [];
        foreach (array_filter($items, $this->isEligible(...)) as $task) {
            $reason = $this->holdReason($task, $open);
            if (null === $reason) {
                $ready[] = $task;

                continue;
            }

            $held[] = new HeldIssue($task, $reason);
        }

        usort($ready, fn (QueuedIssue $a, QueuedIssue $b): int => $this->key($a, $withPullRequest) <=> $this->key($b, $withPullRequest));

        return new IntakePlan($ready, $held);
    }

    /**
     * @param list<QueuedIssue> $items
     *
     * @return array<int, QueuedIssue>
     */
    private function byNumber(array $items): array
    {
        $open = [];
        foreach ($items as $item) {
            $open[$item->number()] = $item;
        }

        return $open;
    }

    /**
     * @param list<QueuedIssue> $items
     *
     * @return array<int, true>
     */
    private function closedByOpenPullRequests(array $items): array
    {
        $closed = [];
        foreach (array_filter($items, static fn (QueuedIssue $item): bool => $item->isPullRequest()) as $pullRequest) {
            foreach ($pullRequest->closedBy() as $number) {
                $closed[$number] = true;
            }
        }

        return $closed;
    }

    private function isEligible(QueuedIssue $item): bool
    {
        return !$item->isPullRequest() && $item->hasLabel($this->eligibilityLabel) && !$item->hasLabel(WorkLabels::IDEA) && $item->isTrusted();
    }

    /**
     * @param array<int, QueuedIssue> $open
     */
    private function holdReason(QueuedIssue $task, array $open): ?string
    {
        if (null !== $task->failedReason()) {
            return 'failed for good: '.$task->failedReason();
        }

        $targets = $this->waitedFor($task, $open);
        $failed = array_find($targets, static fn (QueuedIssue $target): bool => null !== $target->failedReason());
        if (null !== $failed) {
            return sprintf('depends on #%d, which failed for good: %s', $failed->number(), $failed->failedReason());
        }

        return [] === $targets ? null : 'waits for '.implode(', ', array_map(static fn (QueuedIssue $target): string => '#'.$target->number(), $targets));
    }

    /**
     * @param array<int, QueuedIssue> $open
     *
     * @return list<QueuedIssue>
     */
    private function waitedFor(QueuedIssue $task, array $open): array
    {
        // A number the listing does not show open counts as closed, because only a complete listing gets here.
        $targets = [];
        foreach ($task->waitsFor() as $number) {
            if ($number !== $task->number() && isset($open[$number])) {
                $targets[] = $open[$number];
            }
        }

        return $targets;
    }

    /**
     * @param array<int, true> $withPullRequest
     *
     * @return array{int, int, int, string, int}
     */
    private function key(QueuedIssue $task, array $withPullRequest): array
    {
        $priority = $task->priority();

        // Only an urgent task goes before the ones whose pull request is open; a missing due date sorts after every one.
        return [
            Priority::Urgent === $priority ? 0 : 1,
            isset($withPullRequest[$task->number()]) ? 0 : 1,
            $priority->rank(),
            $task->milestoneDue() ?? '9999-12-31T23:59:59',
            $task->number(),
        ];
    }
}
