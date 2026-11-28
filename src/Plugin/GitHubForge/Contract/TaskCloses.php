<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;

// Apart from the comments, because only an umbrella whose parts are all closed is closed by plain code.
interface TaskCloses
{
    // The comment first and once per key, then the close as completed, because a close whose comment failed would leave the issue out of every later listing with no word of why; a retry finds the comment and only closes.
    /**
     * @throws ForgeReadFailed
     * @throws ForgeWriteFailed
     * @throws ForgeRateLimited
     */
    public function closeAsCompleted(string $project, int $number, KeyedComment $comment): void;
}
