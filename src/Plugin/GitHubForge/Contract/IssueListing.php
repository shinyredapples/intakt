<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

final readonly class IssueListing
{
    /**
     * @param list<ListedIssue>|null $issues
     */
    private function __construct(
        private ?array $issues,
        public ?string $validator,
    ) {
    }

    // The forge answered that the list is the one the validator was taken from, so nothing was read.
    public static function unchanged(): self
    {
        return new self(null, null);
    }

    /**
     * @param list<ListedIssue> $issues
     */
    public static function listed(array $issues, ?string $validator): self
    {
        return new self($issues, $validator);
    }

    public function isUnchanged(): bool
    {
        return null === $this->issues;
    }

    /**
     * @return list<ListedIssue>
     */
    public function issues(): array
    {
        return $this->issues ?? [];
    }
}
