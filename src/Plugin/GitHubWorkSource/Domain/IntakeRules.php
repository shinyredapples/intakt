<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

// Eligible, then ready, then ordered: docs/design/05-architecture/03-choosing-work.md
final readonly class IntakeRules
{
    private const string IDEA = 'idea';

    public function __construct(
        private string $eligibilityLabel,
    ) {
    }

    /**
     * @param list<QueuedIssue> $items every open issue and pull request of one complete listing
     */
    public function planOf(array $items): IntakePlan
    {
        $open = [];
        $withPullRequest = [];
        foreach ($items as $item) {
            $open[$item->number()] = $item;
            foreach ($item->isPullRequest() ? $item->closedBy() : [] as $closed) {
                $withPullRequest[$closed] = true;
            }
        }

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

    private function isEligible(QueuedIssue $item): bool
    {
        return !$item->isPullRequest() && $item->hasLabel($this->eligibilityLabel) && !$item->hasLabel(self::IDEA) && $item->isTrusted();
    }

    /**
     * @param array<int, QueuedIssue> $open
     */
    private function holdReason(QueuedIssue $task, array $open): ?string
    {
        if (null !== $task->failedReason()) {
            return 'failed for good: '.$task->failedReason();
        }

        // A number the listing does not show open counts as closed, because only a complete listing gets here.
        $targets = [];
        foreach ($task->waitsFor() as $number) {
            if ($number !== $task->number() && isset($open[$number])) {
                $targets[] = $open[$number];
            }
        }

        foreach ($targets as $target) {
            if (null !== $target->failedReason()) {
                return sprintf('depends on #%d, which failed for good: %s', $target->number(), $target->failedReason());
            }
        }

        return [] === $targets ? null : 'waits for '.implode(', ', array_map(static fn (QueuedIssue $target): string => '#'.$target->number(), $targets));
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
