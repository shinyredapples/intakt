<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Memory\Contract\MemoryQuery;
use Intakt\Core\Memory\Contract\ProjectMemory;
use Intakt\Core\Memory\Contract\RelevantMemory;
use Override;
use Psr\Log\LoggerInterface;
use Throwable;

// Memory only helps a review, so a project without an embeddings account or a set-up store reviews without it instead of failing every reviewer.
final readonly class OptionalProjectMemory implements ProjectMemory
{
    public function __construct(
        private ProjectMemory $memory,
        private LoggerInterface $logger,
    ) {
    }

    #[Override]
    public function relevantTo(MemoryQuery $query): RelevantMemory
    {
        try {
            return $this->memory->relevantTo($query);
        } catch (Throwable $failure) {
            $this->logger->warning('Project memory could not be read; the review goes on without it.', ['project' => $query->project, 'exception' => $failure]);

            return RelevantMemory::within([], $query->maxBytes);
        }
    }
}
