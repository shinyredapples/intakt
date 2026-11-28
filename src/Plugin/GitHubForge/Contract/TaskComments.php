<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;

// Apart from the reads, because only a step that asks the task's author something writes to the task.
interface TaskComments
{
    // Once per key, because a forge can store a comment and still answer as failed, and the caller's retry must not post it again: the issue's comments are read first, and a comment of this App that carries the key stands for the post. A failed read posts nothing.
    /**
     * @param string $key lowercase letters, digits and hyphens
     *
     * @throws ForgeReadFailed
     * @throws ForgeWriteFailed
     * @throws ForgeRateLimited
     */
    public function postOnce(string $project, int $number, string $body, string $key): void;
}
