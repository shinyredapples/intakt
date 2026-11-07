<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

// Apart from ReviewerRuns, because where a fallback is recorded changes with the ledger, not with how the agent runs.
final readonly class StreamingFallbacks
{
    public function __construct(
        private Ledger $ledger,
        private LoggerInterface $logger,
    ) {
    }

    // Written to the ledger with the reviewer's id, because the dashboard and the owner then see which reviews ran without live text; outside a round there is nothing to attach it to.
    public function record(ReviewerConversation $reviewer): void
    {
        $round = $reviewer->round;

        if (!$round instanceof Uuid) {
            return;
        }

        // The ledger's reviewer id when there is one, because a retry runs under a new conversation id.
        $payload = json_encode(['round' => $round->toRfc4122(), 'reviewer' => ($reviewer->reviewerId ?? $reviewer->id)->toRfc4122()], JSON_THROW_ON_ERROR);
        $appended = $this->ledger->append(new LedgerEvent(LedgerEventKind::StreamingFellBack, $payload));

        // Logged, not thrown, because the review goes on unstreamed without its record.
        if (!$appended->succeeded()) {
            $this->logger->error('A streaming fallback was not recorded.', ['fallback' => $payload, 'reason' => $appended->failure]);
        }
    }
}
