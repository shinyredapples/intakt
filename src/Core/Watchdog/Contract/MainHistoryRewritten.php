<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Contract;

use RuntimeException;

// Not a failed read: the history answered, and the answer is that main dropped the commit, so the classifier reports it as history_rewritten.
final class MainHistoryRewritten extends RuntimeException
{
    public static function dropped(string $commit, string $tip): self
    {
        return new self(sprintf('Main is at %s, which no longer holds %s.', $tip, $commit));
    }
}
