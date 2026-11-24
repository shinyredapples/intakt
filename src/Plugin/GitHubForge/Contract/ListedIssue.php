<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Because GitHub lists issues and pull requests together, one value holds both and says which it is.
#[Exclude]
final readonly class ListedIssue
{
    public function __construct(
        public int $number,
        public string $title,
        public string $body,
        public IssueLabels $labels,
        public string $author,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $milestoneDue,
        public bool $isPullRequest,
    ) {
    }
}
