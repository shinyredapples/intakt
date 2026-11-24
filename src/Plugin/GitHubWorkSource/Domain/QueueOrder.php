<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

// Work that finishes an open pull request goes before work that starts a task, also an urgent one, because docs/design/05-architecture/03-choosing-work.md step 5 fixes it; a missing due date sorts last.
final readonly class QueueOrder
{
    /**
     * @param list<QueuedIssue> $tasks
     * @param array<int, int>   $pullRequests
     *
     * @return list<QueuedIssue>
     */
    public static function of(array $tasks, array $pullRequests): array
    {
        usort($tasks, static fn (QueuedIssue $a, QueuedIssue $b): int => self::key($a, $pullRequests) <=> self::key($b, $pullRequests));

        return $tasks;
    }

    /**
     * @param array<int, int> $pullRequests
     *
     * @return array{int, int, int, int}
     */
    private static function key(QueuedIssue $task, array $pullRequests): array
    {
        return [
            isset($pullRequests[$task->number()]) ? 0 : 1,
            $task->priority()->rank(),
            $task->milestoneDue()?->getTimestamp() ?? PHP_INT_MAX,
            $task->number(),
        ];
    }
}
