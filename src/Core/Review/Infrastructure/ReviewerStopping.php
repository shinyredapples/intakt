<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\ReviewerConversation;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\Component\Uid\Uuid;

// Apart from LiveProgress, because whether a reviewer goes on is decided by the ledger, not by what the dashboard sees of it.
final readonly class ReviewerStopping
{
    public function __construct(
        private StopRequests $requests,
    ) {
    }

    // Checked at every progress update, because only then does the agent hand control back: a request made before the call or during a retry wait lands at the first one, and a model silent for long stops only once it reports progress.
    public function watching(ExecutionInterface $execution, ReviewerConversation $reviewer): ExecutionInterface
    {
        $round = $reviewer->round;
        $id = $reviewer->reviewerId;

        if (!$round instanceof Uuid || !$id instanceof Uuid) {
            return $execution;
        }

        return $execution->onProgress(function () use ($execution, $round, $id): void {
            if ($this->requests->pendingFor($round, $id)) {
                $execution->cancel();
            }
        });
    }
}
