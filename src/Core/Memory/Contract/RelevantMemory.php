<?php

declare(strict_types=1);

namespace Intakt\Core\Memory\Contract;

final readonly class RelevantMemory
{
    /**
     * @param list<MemoryEntry> $entries most relevant first
     */
    private function __construct(
        public array $entries,
    ) {
    }

    /**
     * @param iterable<MemoryEntry> $ranked most relevant first
     */
    public static function within(iterable $ranked, int $maxBytes): self
    {
        $entries = [];
        $used = 0;

        foreach ($ranked as $entry) {
            $used += strlen($entry->text);

            // Stops at the first entry that does not fit, so a less relevant one never jumps the queue.
            if ($used > $maxBytes) {
                break;
            }

            $entries[] = $entry;
        }

        return new self($entries);
    }
}
