<?php

declare(strict_types=1);

namespace Intakt\Core\BranchCleanup\UseCase\CleanUpBranches;

use Intakt\Core\BranchCleanup\Infrastructure\ProjectForges;
use Intakt\Core\Platform\Contract\AsCommandHandler;
use Psr\Log\LoggerInterface;
use Throwable;

#[AsCommandHandler(handles: CleanUpBranches::class)]
final readonly class CleanUpBranchesHandler
{
    public function __construct(
        private ProjectForges $projects,
        private ProjectCleanup $cleanup,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        foreach ($this->projects->projects() as $project) {
            $this->cleanUp($project);
        }
    }

    private function cleanUp(string $project): void
    {
        try {
            $this->cleanup->run($project);
            // Boundary: one project's forge or mirror failing must not keep the other projects' branches from their weekly run.
        } catch (Throwable $failed) {
            $this->logger->error('Branch cleanup of {project} failed; nothing more of it was deleted this week.', ['project' => $project, 'exception' => $failed]);
        }
    }
}
