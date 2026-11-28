<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\KeyedComment;
use Intakt\Plugin\GitHubForge\Contract\TaskCloses;

// Keeps each close instead of sending it, so an install in the test environment closes umbrellas without GitHub and a test reads what it would have closed.
final class RecordedTaskCloses implements TaskCloses
{
    /** @var list<array{project: string, number: int, comment: KeyedComment}> */
    public array $closed = [];

    // The failure every close answers with, when a test makes GitHub refuse it.
    public ?ForgeWriteFailed $refusal = null;

    public function closeAsCompleted(string $project, int $number, KeyedComment $comment): void
    {
        if ($this->refusal instanceof ForgeWriteFailed) {
            throw $this->refusal;
        }

        $this->closed[] = ['project' => $project, 'number' => $number, 'comment' => $comment];
    }
}
