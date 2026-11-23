<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The author and update time travel with the branch, because a pull request found by its branch counts only once its author and last editor are checked as of that update.
#[Exclude]
final readonly class BranchPullRequest
{
    public function __construct(
        public int $number,
        public string $branch,
        public string $author,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
