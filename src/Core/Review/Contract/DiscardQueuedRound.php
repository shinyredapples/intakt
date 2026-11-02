<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Sent after a transaction that queued a round rolled back, because the round and its messages went with it, but the checkout kept on disk did not, and only the module that made it can remove it.
final readonly class DiscardQueuedRound
{
    public function __construct(public QueuedRound $round)
    {
    }
}
