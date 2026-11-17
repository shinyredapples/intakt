<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ReplayDemo;

use RuntimeException;

final class ReplayIsRefused extends RuntimeException
{
    public static function whileAnotherReplayRuns(): self
    {
        return new self('Another replay is running; start this one when it has ended.');
    }

    public static function becauseTheLedgerHolds(string $round): self
    {
        return new self(sprintf('The ledger already holds review round %s of this recording, so it is not replayed again.', $round));
    }
}
