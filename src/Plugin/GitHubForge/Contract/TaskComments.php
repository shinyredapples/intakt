<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;

// Apart from the reads, because only a step that asks the task's author something writes to the task.
interface TaskComments
{
    /**
     * @throws ForgeWriteFailed
     * @throws ForgeRateLimited
     */
    public function post(string $project, int $number, string $body): void;
}
