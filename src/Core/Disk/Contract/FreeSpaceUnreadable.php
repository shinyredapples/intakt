<?php

declare(strict_types=1);

namespace Intakt\Core\Disk\Contract;

use RuntimeException;

final class FreeSpaceUnreadable extends RuntimeException
{
    public function __construct(string $folder, string $reason)
    {
        parent::__construct(sprintf('The free space of %s cannot be read: %s.', $folder, $reason));
    }
}
