<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

use Intakt\Plugin\GitHubWorkSource\Contract\WorkLabels;

// Eligible, then ready, then ordered, because docs/design/05-architecture/03-choosing-work.md fixes that sequence.
final readonly class IntakeRules
{
    public function __construct(
        private string $eligibilityLabel,
    ) {
    }

    // Only these items can steer the queue, so only their history is worth a read: a task candidate, a pull request whose closing keywords reorder or release tasks, and a pull request a candidate waits for, because one left unread would pass for an outsider's and the wait would be dropped.
    /**
     * @param list<QueuedIssue> $items
     *
     * @return list<QueuedIssue>
     */
    public function itemsNeedingHistory(array $items): array
    {
        $candidates = array_filter($items, $this->isCandidate(...));
        $waited = array_merge([], ...array_map(static fn (QueuedIssue $task): array => $task->waitsFor(), $candidates));

        return array_values(array_filter($items, fn (QueuedIssue $item): bool => $this->isCandidate($item) || $this->steersAsPullRequest($item, $waited)));
    }

    // The failures hold every task of the project that failed for good, open or closed, because a closed failure still holds its dependents.
    /**
     * @param list<QueuedIssue>  $items
     * @param array<int, string> $failures
     */
    public function planOf(array $items, array $failures): IntakePlan
    {
        $open = $this->byNumber($items);
        $pullRequests = $this->pullRequestsOf($items);

        $ready = [];
        $held = [];
        $dependsOn = [];
        foreach (array_filter($items, $this->isEligible(...)) as $task) {
            $reason = $this->holdReason($task, $open, $failures);
            if (null === $reason) {
                $ready[] = $task;
                $dependsOn[$task->number()] = $this->targetsOf($task, $open);

                continue;
            }

            $held[] = new HeldIssue($task, $reason);
        }

        usort($ready, fn (QueuedIssue $a, QueuedIssue $b): int => $this->key($a, $pullRequests) <=> $this->key($b, $pullRequests));

        $openPullRequests = array_map(static fn (QueuedIssue $item): int => $item->number(), array_values(array_filter($items, static fn (QueuedIssue $item): bool => $item->isPullRequest())));

        return new IntakePlan($ready, $held, $pullRequests, $dependsOn, $openPullRequests);
    }

    private function isCandidate(QueuedIssue $item): bool
    {
        return !$item->isPullRequest() && $item->hasLabel($this->eligibilityLabel) && !$item->hasLabel(WorkLabels::IDEA);
    }

    /**
     * @param list<int> $waited
     */
    private function steersAsPullRequest(QueuedIssue $item, array $waited): bool
    {
        return $item->isPullRequest() && ([] !== $item->closedBy() || in_array($item->number(), $waited, true));
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

    // The lowest open pull request that closes each task, and only one whose author and last editor have write access, because anyone can open one that says `Fixes #n` and would move that task ahead.
    /**
     * @param list<QueuedIssue> $items
     *
     * @return array<int, int>
     */
    private function pullRequestsOf(array $items): array
    {
        $pullRequests = [];
        foreach (array_filter($items, $this->isTrustedPullRequest(...)) as $pullRequest) {
            foreach ($pullRequest->closedBy() as $number) {
                $pullRequests[$number] = min($pullRequests[$number] ?? $pullRequest->number(), $pullRequest->number());
            }
        }

        return $pullRequests;
    }

    private function isTrustedPullRequest(QueuedIssue $item): bool
    {
        return $item->isPullRequest() && $item->isTrusted();
    }

    private function isEligible(QueuedIssue $item): bool
    {
        return $this->isCandidate($item) && $item->hasVouchedLabel($this->eligibilityLabel) && $item->isTrusted();
    }

    /**
     * @param array<int, QueuedIssue> $open
     * @param array<int, string>      $failures
     */
    private function holdReason(QueuedIssue $task, array $open, array $failures): ?string
    {
        if (isset($failures[$task->number()])) {
            return 'failed for good: '.$failures[$task->number()];
        }

        // A wait Intakt cannot read holds the task, because building it before its base costs more than asking for the line in the usual form.
        if ([] !== $task->unreadableWaits()) {
            return 'unreadable wait: '.implode(', ', array_map(static fn (string $line): string => '"'.$line.'"', $task->unreadableWaits()));
        }

        $targets = $this->targetsOf($task, $open);
        $failed = array_find($targets, static fn (int $target): bool => isset($failures[$target]));
        if (null !== $failed) {
            return sprintf('depends on #%d, which failed for good: %s', $failed, $failures[$failed]);
        }

        // A number the listing does not show open counts as closed, because only a complete listing gets here.
        $waiting = array_filter($targets, static fn (int $target): bool => isset($open[$target]));

        return [] === $waiting ? null : 'waits for '.implode(', ', array_map(static fn (int $target): string => '#'.$target, $waiting));
    }

    /**
     * @param array<int, QueuedIssue> $open
     *
     * @return list<int>
     */
    private function targetsOf(QueuedIssue $task, array $open): array
    {
        $targets = [];
        foreach ($task->waitsFor() as $number) {
            $targets = [...$targets, ...$this->resolved($task, $number, $open[$number] ?? null)];
        }

        return array_values(array_unique(array_diff($targets, [$task->number()])));
    }

    // A trusted pull request stands for the tasks it closes, because the work waited for is theirs; one that closes the waiting task is its own and no wait, because the build hands over instead; anyone else's is no wait at all, because anyone can open one and hold a task with it.
    /**
     * @return list<int>
     */
    private function resolved(QueuedIssue $task, int $number, ?QueuedIssue $item): array
    {
        if ($item instanceof QueuedIssue && $item->isPullRequest() && !$item->isTrusted()) {
            return [];
        }

        if (!$item instanceof QueuedIssue || !$item->isPullRequest() || [] === $item->closedBy()) {
            return [$number];
        }

        return in_array($task->number(), $item->closedBy(), true) ? [] : $item->closedBy();
    }

    /**
     * @param array<int, int> $pullRequests
     *
     * @return array{int, int, int, int, int}
     */
    private function key(QueuedIssue $task, array $pullRequests): array
    {
        $priority = $task->priority();

        // Only an urgent task goes before the ones whose pull request is open, because finished work waiting on a small step is cheaper to land; a missing due date sorts last.
        return [
            Priority::Urgent === $priority ? 0 : 1,
            isset($pullRequests[$task->number()]) ? 0 : 1,
            $priority->rank(),
            $task->milestoneDue()?->getTimestamp() ?? PHP_INT_MAX,
            $task->number(),
        ];
    }
}
