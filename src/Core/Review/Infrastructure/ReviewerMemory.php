<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Memory\Contract\MemoryEntry;
use Intakt\Core\Memory\Contract\MemoryQuery;
use Intakt\Core\Memory\Contract\ProjectMemory;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\FencedText;
use Intakt\Core\Review\Contract\PromptAssembly;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\UntrustedSource;
use Symfony\AI\Platform\Message\UserMessage;

final readonly class ReviewerMemory
{
    // A fixed cap, because every byte is resent with each round of the conversation; about a page of notes is enough to cite earlier findings.
    private const int MAX_BYTES = 4_000;

    private const string SHORTENED = ' [note shortened]';

    private const string LEAD = 'Project memory, notes earlier reviews left about the files under review:';

    public function __construct(
        private ProjectMemory $memory,
        private Fence $fence,
        private DiffPaths $paths,
        // Defaulted because the assembly has no state or settings to wire, so every place that builds the prompt gets the same one.
        private PromptAssembly $assembly = new PromptAssembly(),
    ) {
    }

    public function briefingOn(ReviewBrief $brief): ?UserMessage
    {
        $room = self::MAX_BYTES - $this->bytesOf($this->fenced(''));
        $entries = $this->memory->relevantTo(new MemoryQuery($brief->change->project, new Paths(...$this->paths->in($brief->change->diff)), $room))->entries;
        $notes = $this->notesWithin($entries);

        return $notes instanceof FencedText ? $this->assembly->user(self::LEAD, $notes) : null;
    }

    private function fenced(string $notes): FencedText
    {
        return $this->fence->wrap(UntrustedSource::ProjectMemory, $notes);
    }

    // Measured as the whole message, introduction included, because the cap holds for what the model receives.
    private function bytesOf(FencedText $notes): int
    {
        return strlen($this->assembly->userText(self::LEAD, $notes));
    }

    // The separators still add bytes the memory does not count, so the least relevant entries are dropped until the whole briefing fits; a lone top entry is shortened instead, because the most relevant note is worth more than none.
    /**
     * @param list<MemoryEntry> $entries
     */
    private function notesWithin(array $entries): ?FencedText
    {
        while ([] !== $entries) {
            $notes = $this->notesOf($entries);

            if ($this->bytesOf($notes) <= self::MAX_BYTES) {
                return $notes;
            }

            if (1 === count($entries)) {
                return $this->shortened($entries[0]);
            }

            array_pop($entries);
        }

        return null;
    }

    /**
     * @param non-empty-list<MemoryEntry> $entries
     */
    private function notesOf(array $entries): FencedText
    {
        return $this->fenced(implode("\n\n", array_map(static fn (MemoryEntry $entry): string => $entry->text, $entries)));
    }

    private function shortened(MemoryEntry $entry): FencedText
    {
        $room = self::MAX_BYTES - $this->bytesOf($this->fenced('')) - strlen(self::SHORTENED);

        // mb_strcut cuts at a character boundary, so the text stays valid UTF-8.
        return $this->fenced(mb_strcut($entry->text, 0, $room, 'UTF-8').self::SHORTENED);
    }
}
