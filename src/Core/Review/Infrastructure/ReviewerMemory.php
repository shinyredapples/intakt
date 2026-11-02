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

    private const string SHORTENED = ' [note shortened]';

    public function __construct(
        private ProjectMemory $memory,
        private Fence $fence,
        private DiffPaths $paths,
    ) {
    }

    public function briefingOn(ReviewBrief $brief): ?string
    {
        $room = self::MAX_BYTES - strlen($this->wrapped(''));
        $entries = $this->memory->relevantTo(new MemoryQuery($brief->change->project, $this->paths->in($brief->change->diff), $room))->entries;

        return $this->briefingWithin($entries);
    }

    // The separators still add bytes the memory does not count, so the least relevant entries are dropped until the whole briefing fits; a lone top entry is shortened instead, because the most relevant note is worth more than none.
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

            if (1 === count($entries)) {
                return $this->shortened($entries[0]);
            }

            array_pop($entries);
        }

        return null;
    }

    private function shortened(MemoryEntry $entry): string
    {
        $room = self::MAX_BYTES - strlen($this->wrapped('')) - strlen(self::SHORTENED);

        // mb_strcut cuts at a character boundary, so the text stays valid UTF-8.
        return $this->wrapped(mb_strcut($entry->text, 0, $room, 'UTF-8').self::SHORTENED);
    }

    /**
     * @param non-empty-list<MemoryEntry> $entries
     */
    private function briefingOf(array $entries): string
    {
        return $this->wrapped(implode("\n\n", array_map(static fn (MemoryEntry $entry): string => $entry->text, $entries)));
    }

    private function wrapped(string $notes): string
    {
        return "Project memory, notes earlier reviews left about the files under review:\n\n".$this->fence->wrap(UntrustedSource::ProjectMemory, $notes);
    }
}
