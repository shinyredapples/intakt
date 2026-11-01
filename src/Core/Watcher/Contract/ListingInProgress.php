<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\Contract;

use RuntimeException;

final class ListingInProgress extends RuntimeException
{
    public static function for(string $project): self
    {
        return new self(sprintf('Another listing of the open pull requests of project "%s" is running.', $project));
    }
}
