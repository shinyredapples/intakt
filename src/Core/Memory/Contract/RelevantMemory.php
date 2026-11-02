<?php

declare(strict_types=1);

namespace Intakt\Core\Memory\Contract;

final readonly class RelevantMemory
{
    /**
     * @param list<MemoryEntry> $entries
     */
    private function __construct(
        public array $entries,
    ) {
    }

    /**
     * @param iterable<MemoryEntry> $ranked
     */
    public static function within(iterable $ranked, int $maxBytes): self
    {
        $entries = [];
        $used = 0;

        foreach ($ranked as $entry) {
            $used += strlen($entry->text);

            // Stops at the first entry that does not fit, so a less relevant one never jumps the queue; the most relevant one is kept even over the cap, so the caller can shorten it instead of getting nothing.
            if ($used > $maxBytes && [] !== $entries) {
                break;
            }

            $entries[] = $entry;
        }

        return new self($entries);
    }
}
