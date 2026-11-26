<?php

declare(strict_types=1);

namespace Intakt\Core\Disk\Contract;

use RuntimeException;
use Throwable;

final class FreeSpaceUnreadable extends RuntimeException
{
    public function __construct(string $folder, string $reason, ?Throwable $previous = null)
    {
        parent::__construct(sprintf('The free space of %s cannot be read: %s.', $folder, $reason), 0, $previous);
    }
}
