<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\IndexResolvedFindings;

use Intakt\Core\Memory\Contract\MemoryEntry;
use Intakt\Core\Memory\Contract\MemoryIndex;
use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Domain\TrackedFinding;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;

// Indexing the same entry twice keeps one copy, so a redelivered message does no harm.
#[AsCommandHandler]
final readonly class IndexResolvedFindingsHandler
{
    public function __construct(
        private ReviewRounds $rounds,
        private StoredFindings $findings,
        private MemoryIndex $memory,
    ) {
    }

    public function __invoke(IndexResolvedFindings $command): void
    {
        $round = $this->rounds->stored($command->roundId);

        foreach ($this->findings->resolvedBy($round) as $tracked) {
            $this->memory->index($this->entryOf($tracked));
        }
    }

    private function entryOf(TrackedFinding $tracked): MemoryEntry
    {
        $finding = $tracked->latest;
        $lines = ['Resolved finding: '.$finding->text];

        if (null !== $finding->requirement) {
            $lines[] = 'Cause: '.$finding->requirement;
        }

        if (null !== $finding->evidence) {
            $lines[] = 'Evidence: '.$finding->evidence->probe.': '.$finding->evidence->output;
        }

        return new MemoryEntry(implode(PHP_EOL, $lines), null === $finding->file ? [] : [$finding->file]);
    }
}
