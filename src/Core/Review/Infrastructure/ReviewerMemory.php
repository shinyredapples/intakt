<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Memory\Contract\MemoryEntry;
use Intakt\Core\Memory\Contract\MemoryQuery;
use Intakt\Core\Memory\Contract\ProjectMemory;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\UntrustedSource;

final readonly class ReviewerMemory
{
    // A fixed cap, because every byte is resent with each round of the conversation; about a page of notes is enough to cite earlier findings.
    private const int MAX_BYTES = 4_000;

    public function __construct(
        private ProjectMemory $memory,
        private Fence $fence,
        private DiffPaths $paths,
    ) {
    }

    public function briefingOn(ReviewBrief $brief): ?string
    {
        $entries = $this->memory->relevantTo(new MemoryQuery($brief->change->project, $this->paths->in($brief->change->diff), self::MAX_BYTES))->entries;

        return $this->briefingWithin($entries);
    }

    // The memory caps the entry texts only, so the least relevant entries are dropped until the whole briefing, separators, fence and introduction included, fits.
    /**
     * @param list<MemoryEntry> $entries
     */
    private function briefingWithin(array $entries): ?string
    {
        while ([] !== $entries) {
            $briefing = $this->briefingOf($entries);

            if (strlen($briefing) <= self::MAX_BYTES) {
                return $briefing;
            }

            array_pop($entries);
        }

        return null;
    }

    /**
     * @param non-empty-list<MemoryEntry> $entries
     */
    private function briefingOf(array $entries): string
    {
        $notes = implode("\n\n", array_map(static fn (MemoryEntry $entry): string => $entry->text, $entries));

        return "Project memory, notes earlier reviews left about the files under review:\n\n".$this->fence->wrap(UntrustedSource::ProjectMemory, $notes);
    }
}
