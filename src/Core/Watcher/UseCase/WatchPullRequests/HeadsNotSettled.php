<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use RuntimeException;
use Throwable;

final class HeadsNotSettled extends RuntimeException
{
    /**
     * @param non-empty-list<Throwable> $failures
     */
    public static function after(array $failures): self
    {
        $reasons = implode('; ', array_map(static fn (Throwable $failure): string => $failure->getMessage(), $failures));

        return new self(sprintf('%d head(s) stay due for the next tick: %s', count($failures), $reasons), 0, $failures[0]);
    }
}
