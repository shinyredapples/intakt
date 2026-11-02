<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use RuntimeException;

final class WatcherEventNotRecorded extends RuntimeException
{
    public static function for(string $kind, string $reason): self
    {
        return new self(sprintf('The ledger did not record the watcher event "%s": %s', $kind, $reason));
    }
}
