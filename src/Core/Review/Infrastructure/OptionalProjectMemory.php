<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Memory\Contract\MemoryQuery;
use Intakt\Core\Memory\Contract\ProjectMemory;
use Intakt\Core\Memory\Contract\ProjectMemoryNeedsEmbeddingModel;
use Intakt\Core\Memory\Contract\RelevantMemory;
use Override;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

// Memory only helps a review, so a project without an embeddings account or a set-up store reviews without it instead of failing every reviewer.
final readonly class OptionalProjectMemory implements ProjectMemory
{
    // Per-process state on purpose: the notice is once per start, so a reset between messages would bring back one line per review. A readonly property holds a mutable marker to keep the stateful-service gate honest.
    private stdClass $notice;

    public function __construct(
        private ProjectMemory $memory,
        private LoggerInterface $logger,
    ) {
        $this->notice = new stdClass();
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
        if (isset($this->notice->reported)) {
            return;
        }

        $this->notice->reported = true;
        $this->logger->info($missing->getMessage(), ['project' => $project]);
    }
}
