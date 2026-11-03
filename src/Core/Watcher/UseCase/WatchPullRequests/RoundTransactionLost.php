<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use RuntimeException;

final class RoundTransactionLost extends RuntimeException
{
    public static function for(string $roundId): self
    {
        return new self(sprintf('The transaction of round "%s" ended before the round was recorded.', $roundId));
    }
}
