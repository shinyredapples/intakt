<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\IndexResolvedFindings;

use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Memory\Contract\MemoryEntry;
use Intakt\Core\Memory\Contract\MemoryIndex;
use Intakt\Core\Memory\Contract\ProjectMemoryNeedsEmbeddingModel;
use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Contract\Evidence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Psr\Log\LoggerInterface;

// Indexing the same entry twice keeps one copy, so a redelivered message does no harm.
#[AsCommandHandler]
final readonly class IndexResolvedFindingsHandler
{
    public function __construct(
        private ReviewRounds $rounds,
        private StoredFindings $findings,
        private MemoryIndex $memory,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(IndexResolvedFindings $command): void
    {
        $round = $this->rounds->stored($command->roundId);

        try {
            $this->indexResolvedFindingsOf($round);
        } catch (ProjectMemoryNeedsEmbeddingModel $missing) {
            // Done instead of retried, because nothing is broken: a retry would fail the same way and end in the failure transport with a trace.
            $this->logger->info($missing->getMessage(), ['project' => $round->project()]);
        }
    }

    private function indexResolvedFindingsOf(ReviewRound $round): void
    {
        foreach ($this->findings->resolvedBy($round) as $finding) {
            $this->memory->index($this->entryOf($round->project(), $finding));
        }
    }

    private function entryOf(string $project, Finding $finding): MemoryEntry
    {
        $lines = ['Resolved finding: '.$finding->text];

        if (null !== $finding->requirement) {
            $lines[] = 'Cause: '.$finding->requirement;
        }

        if ($finding->evidence instanceof Evidence) {
            $lines[] = 'Evidence: '.$finding->evidence->probe.': '.$finding->evidence->output;
        }

        return new MemoryEntry($project, implode(PHP_EOL, $lines), null === $finding->file ? new Paths() : new Paths($finding->file));
    }
}
