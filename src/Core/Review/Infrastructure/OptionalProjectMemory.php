<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use ArrayObject;
use Intakt\Core\Memory\Contract\MemoryQuery;
use Intakt\Core\Memory\Contract\ProjectMemory;
use Intakt\Core\Memory\Contract\ProjectMemoryNeedsEmbeddingModel;
use Intakt\Core\Memory\Contract\RelevantMemory;
use Override;
use Psr\Log\LoggerInterface;
use Throwable;

// Memory only helps a review, so a project without an embeddings account or a set-up store reviews without it instead of failing every reviewer.
final readonly class OptionalProjectMemory implements ProjectMemory
{
    /** @var ArrayObject<int, string> */
    private ArrayObject $reported;

    public function __construct(
        private ProjectMemory $memory,
        private LoggerInterface $logger,
    ) {
        $this->reported = new ArrayObject();
    }

    #[Override]
    public function relevantTo(MemoryQuery $query): RelevantMemory
    {
        try {
            return $this->memory->relevantTo($query);
        } catch (ProjectMemoryNeedsEmbeddingModel $missing) {
            $this->reportMissingModelOnce($query->project, $missing);
        } catch (Throwable $failure) {
            $this->logger->warning('Project memory could not be read; the review goes on without it.', ['project' => $query->project, 'exception' => $failure]);
        }

        return RelevantMemory::within([], $query->maxBytes);
    }

    // One info line per start without a trace, because nothing is broken and a per-message reset would bring back one line per review.
    private function reportMissingModelOnce(string $project, ProjectMemoryNeedsEmbeddingModel $missing): void
    {
        if ($this->reported->count() > 0) {
            return;
        }

        $this->reported->append($project);
        $this->logger->info($missing->getMessage(), ['project' => $project]);
    }
}
