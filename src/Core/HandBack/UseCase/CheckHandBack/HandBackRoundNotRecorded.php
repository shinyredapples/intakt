<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\CheckHandBack;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

// Thrown, because a failure the ledger did not take would be sent back again instead of handed over.
final class HandBackRoundNotRecorded extends RuntimeException implements ReportableFailure
{
    public static function for(string $kind, string $failure): self
    {
        return new self(sprintf('the hand-back\'s %s line was not recorded: %s', $kind, $failure));
    }

    public static function unread(string $failure): self
    {
        return new self(sprintf('the hand-back round was not recorded, because the ledger could not be read: %s', $failure));
    }
}
