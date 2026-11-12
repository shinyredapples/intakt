<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

use DateTimeImmutable;

// An open issue or pull request as the forge's one listing shows it; GitHub lists both together.
final readonly class ListedIssue
{
    /**
     * @param list<string> $labels
     */
    public function __construct(
        public int $number,
        public string $title,
        public string $body,
        public array $labels,
        public string $author,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $milestoneDue,
        public bool $isPullRequest,
    ) {
    }
}
