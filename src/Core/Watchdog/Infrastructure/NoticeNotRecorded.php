<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Infrastructure;

use RuntimeException;

final class NoticeNotRecorded extends RuntimeException
{
    public static function noticing(string $commit, string $failure): self
    {
        return new self(sprintf('The notice for %s was not recorded, because the ledger refused it: %s.', $commit, $failure));
    }
}
