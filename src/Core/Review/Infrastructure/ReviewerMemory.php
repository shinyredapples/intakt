<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Memory\Contract\MemoryEntry;
use Intakt\Core\Memory\Contract\MemoryQuery;
use Intakt\Core\Memory\Contract\ProjectMemory;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\UntrustedSource;
use Override;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\Memory\Memory;
use Symfony\AI\Agent\Memory\MemoryProviderInterface;

// Built per review from its diff, because the files under review differ per request and services keep no per-request state.
final readonly class ReviewerMemory implements MemoryProviderInterface
{
    // A fixed cap, because every byte is resent with each round of the conversation; about a page of notes is enough to cite earlier findings.
    private const int MAX_BYTES = 4_000;

    public function __construct(
        private ProjectMemory $memory,
        private Fence $fence,
        private string $diff,
    ) {
    }

    /**
     * @return list<Memory>
     */
    #[Override]
    public function load(Input $input): array
    {
        $entries = $this->memory->relevantTo(new MemoryQuery($this->filesInDiff(), self::MAX_BYTES))->entries;

        if ([] === $entries) {
            return [];
        }

        return [new Memory("Project memory, notes about the files under review:\n\n".$this->fence->wrap(UntrustedSource::ProjectMemory, $this->listOf($entries)))];
    }

    /**
     * @return list<string>
     */
    private function filesInDiff(): array
    {
        // The header repeats the path on both sides, so a path with spaces still splits unambiguously; a quoted header (unusual characters) is skipped.
        preg_match_all('~^diff --git a/(.+) b/\1$~m', $this->diff, $found);

        return array_values(array_unique($found[1]));
    }

    /**
     * @param list<MemoryEntry> $entries
     */
    private function listOf(array $entries): string
    {
        return implode("\n\n", array_map(static fn (MemoryEntry $entry): string => $entry->text, $entries));
    }
}
