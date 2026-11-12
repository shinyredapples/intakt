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

    // Only these items can steer the queue, so only their history is worth a read: a task candidate, and a pull request whose closing keywords reorder or release tasks.
    /**
     * @param list<string> $labels
     * @param list<int>    $closedBy
     */
    public function needsHistory(bool $isPullRequest, array $labels, array $closedBy): bool
    {
        if ($isPullRequest) {
            return [] !== $closedBy;
        }

        return in_array($this->eligibilityLabel, $labels, true) && !in_array(WorkLabels::IDEA, $labels, true);
    }

    /**
     * @param list<QueuedIssue>  $items
     * @param array<int, string> $failures the reason of every task of the project that failed for good, open or closed
     */
    public function planOf(array $items, array $failures): IntakePlan
    {
        $open = $this->byNumber($items);
        $pullRequests = $this->pullRequestsOf($items);

        $ready = [];
        $held = [];
        foreach (array_filter($items, $this->isEligible(...)) as $task) {
            $reason = $this->holdReason($task, $open, $failures);
            if (null === $reason) {
                $ready[] = $task;

                continue;
            }

            $held[] = new HeldIssue($task, $reason);
        }

        usort($ready, fn (QueuedIssue $a, QueuedIssue $b): int => $this->key($a, $pullRequests) <=> $this->key($b, $pullRequests));

        return new IntakePlan($ready, $held, $pullRequests);
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

    // Only a pull request whose author and last editor have write access, because anyone can open one that says `Fixes #n` and would move that task ahead.
    /**
     * @param list<QueuedIssue> $items
     *
     * @return array<int, int> the lowest open pull request that closes each task
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
        return !$item->isPullRequest() && $item->hasVouchedLabel($this->eligibilityLabel) && !$item->hasLabel(WorkLabels::IDEA) && $item->isTrusted();
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

    // A trusted pull request stands for the tasks it closes, because the work waited for is theirs; one that closes the waiting task is its own and no wait, because the build hands over instead.
    /**
     * @return list<int>
     */
    private function resolved(QueuedIssue $task, int $number, ?QueuedIssue $item): array
    {
        if (!$item instanceof QueuedIssue || !$this->isTrustedPullRequest($item) || [] === $item->closedBy()) {
            return [$number];
        }

        return in_array($task->number(), $item->closedBy(), true) ? [] : $item->closedBy();
    }

    /**
     * @param array<int, int> $pullRequests
     *
     * @return array{int, int, int, string, int}
     */
    private function key(QueuedIssue $task, array $pullRequests): array
    {
        $priority = $task->priority();

        // Only an urgent task goes before the ones whose pull request is open, because finished work waiting on a small step is cheaper to land; a missing due date sorts last.
        return [
            Priority::Urgent === $priority ? 0 : 1,
            isset($pullRequests[$task->number()]) ? 0 : 1,
            $priority->rank(),
            $task->milestoneDue() ?? '9999-12-31T23:59:59',
            $task->number(),
        ];
    }
}
