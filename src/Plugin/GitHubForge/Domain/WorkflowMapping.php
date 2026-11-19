<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

final readonly class WorkflowMapping
{
    /**
     * @param list<WorkflowEntry> $entries
     */
    public function __construct(
        public int $line,
        public array $entries,
    ) {
    }

    public function entry(string $key): ?WorkflowEntry
    {
        return array_find($this->entries, static fn (WorkflowEntry $entry): bool => $entry->key === $key);
    }

    /**
     * @return list<WorkflowEntry>
     */
    public function entriesInAnotherCase(string $key): array
    {
        return array_values(array_filter($this->entries, static fn (WorkflowEntry $entry): bool => $entry->key !== $key && 0 === strcasecmp($entry->key, $key)));
    }
}
