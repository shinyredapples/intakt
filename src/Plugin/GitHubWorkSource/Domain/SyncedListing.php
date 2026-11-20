<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Short carries no items at all, because a pick or a wait computed over part of the listing would sort the wrong task first and close waits that are still open.
#[Exclude]
final readonly class SyncedListing
{
    /**
     * @param list<QueuedIssue> $items
     */
    private function __construct(
        public array $items,
        public ?string $shortBecause,
    ) {
    }

    /**
     * @param list<QueuedIssue> $items
     */
    public static function complete(array $items): self
    {
        return new self($items, null);
    }

    public static function short(string $reason): self
    {
        return new self([], $reason);
    }

    public function isComplete(): bool
    {
        return null === $this->shortBecause;
    }
}
