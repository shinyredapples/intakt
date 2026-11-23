<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Infrastructure;

use RuntimeException;

final class HaltNotRecorded extends RuntimeException
{
    public static function raising(string $commit, string $failure): self
    {
        return new self(sprintf('The halt for %s was not recorded, because the ledger refused it: %s.', $commit, $failure));
    }

    public static function clearing(string $card, string $failure): self
    {
        return new self(sprintf('The clear of card %s was not recorded, because the ledger refused it: %s.', $card, $failure));
    }
}
