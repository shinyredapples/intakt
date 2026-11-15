<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Memory\Contract\MemoryQuery;
use Intakt\Core\Memory\Contract\ProjectMemory;
use Intakt\Core\Memory\Contract\ProjectMemoryNeedsEmbeddingModel;
use Intakt\Core\Memory\Contract\RelevantMemory;
use Override;
use Psr\Log\LoggerInterface;
use Throwable;

// Memory only helps a review, so a project without an embeddings account or a set-up store reviews without it instead of failing every reviewer.
final class OptionalProjectMemory implements ProjectMemory
{
    private bool $missingModelReported = false;

    public function __construct(
        private readonly ProjectMemory $memory,
        private readonly LoggerInterface $logger,
    ) {
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

    // The same condition holds for every call until the operator adds an account, so one line per start is enough and carries no trace because nothing is broken.
    private function reportMissingModelOnce(string $project, ProjectMemoryNeedsEmbeddingModel $missing): void
    {
        if ($this->missingModelReported) {
            return;
        }

        $this->missingModelReported = true;
        $this->logger->info($missing->getMessage(), ['project' => $project]);
    }
}
