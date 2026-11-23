<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The author and update time travel with the branch, because a pull request found by its branch counts only once its author and last editor are checked as of that update. The body travels too, because an edit in the second of an earlier listing keeps the update time, so only the text tells which tasks the pull request closes now.
#[Exclude]
final readonly class BranchPullRequest
{
    public function __construct(
        public int $number,
        public string $branch,
        public string $author,
        public DateTimeImmutable $updatedAt,
        public string $body,
    ) {
    }
}
