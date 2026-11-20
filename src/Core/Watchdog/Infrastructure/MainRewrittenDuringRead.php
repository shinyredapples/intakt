<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Infrastructure;

use RuntimeException;

final class MainRewrittenDuringRead extends RuntimeException
{
    public static function dropped(string $commit, string $tip): self
    {
        // A failed read, never an empty walk, because walking from a head main no longer holds would report the rewrite as clean.
        return new self(sprintf('Main moved to %s, which no longer holds %s, between the ancestry check and the walk.', $tip, $commit));
    }
}
