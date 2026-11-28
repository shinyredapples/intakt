<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\SizeTask;

use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
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

    // A post the forge refuses: nothing is stored.
    public ?ForgeWriteFailed $failure = null;

    public ?ForgeReadFailed $unreadable = null;

    #[Override]
    public function postOnce(string $project, int $number, string $body, string $key): void
    {
        if ($this->unreadable instanceof ForgeReadFailed) {
            throw $this->unreadable;
        }

        if (in_array($key, $this->keys, true)) {
            return;
        }

        if ($this->failure instanceof ForgeWriteFailed) {
            throw $this->failure;
        }

        $this->posted[] = [$project, $number, $body];
        $this->keys[] = $key;
    }
}
