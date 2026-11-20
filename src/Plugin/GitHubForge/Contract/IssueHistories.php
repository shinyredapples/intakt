<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

use DateTimeImmutable;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;

// A failure instead of an empty history, because a history that could not be read must not read as one nobody steered.
interface IssueHistories
{
    // The issue or pull request of that number, and only as it stood when listed: one updated since fails the read, because its history may be of text and labels the listing does not hold.
    /**
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     */
    public function of(string $project, int $number, DateTimeImmutable $listedAt): IssueHistory;
}
