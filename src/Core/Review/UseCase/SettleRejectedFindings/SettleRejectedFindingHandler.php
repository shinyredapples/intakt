<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\SettleRejectedFindings;

use Intakt\Core\Memory\Contract\MemoryIndex;
use Intakt\Core\Memory\Contract\ProjectMemoryNeedsEmbeddingModel;
use Intakt\Core\Memory\Contract\SettledFinding;
use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Infrastructure\SettledCode;
use Psr\Log\LoggerInterface;

// Settling the same finding twice keeps one entry, so a redelivered message does no harm.
#[AsCommandHandler]
final readonly class SettleRejectedFindingHandler
{
    public function __construct(
        private MemoryIndex $memory,
        private SettledCode $code,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SettleRejectedFinding $command): void
    {
        try {
            $this->memory->settle(new SettledFinding($command->project, $command->text, $command->reason, $this->code->recorded($command->file, $command->hash)));
        } catch (ProjectMemoryNeedsEmbeddingModel $missing) {
            // Done instead of retried, because nothing is broken: a retry would fail the same way and end in the failure transport with a trace.
            $this->logger->info($missing->getMessage(), ['project' => $command->project]);
        }
    }
}
