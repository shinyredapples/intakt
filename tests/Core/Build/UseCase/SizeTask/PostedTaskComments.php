<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\SizeTask;

use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\KeyedComment;
use Intakt\Plugin\GitHubForge\Contract\TaskComments;
use Override;

// The comments an issue holds, kept by key as the forge keeps them by marker, so a test sees how many the task's author would see.
final class PostedTaskComments implements TaskComments
{
    /**
     * @var list<array{string, int, string}>
     */
    public array $posted = [];

    /**
     * @var list<string>
     */
    public array $keys = [];

    public ?ForgeWriteFailed $failure = null;

    public ?ForgeReadFailed $unreadable = null;

    #[Override]
    public function postOnce(string $project, int $number, KeyedComment $comment): void
    {
        if ($this->unreadable instanceof ForgeReadFailed) {
            throw $this->unreadable;
        }

        if (in_array($comment->key, $this->keys, true)) {
            return;
        }

        if ($this->failure instanceof ForgeWriteFailed) {
            throw $this->failure;
        }

        $this->posted[] = [$project, $number, $comment->body];
        $this->keys[] = $comment->key;
    }
}
