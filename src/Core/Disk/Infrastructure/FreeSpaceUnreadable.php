<?php

declare(strict_types=1);

namespace Intakt\Core\Disk\Infrastructure;

use RuntimeException;

final class FreeSpaceUnreadable extends RuntimeException
{
    public static function of(string $folder, string $what): self
    {
        return new self(sprintf('the free %s of "%s" could not be read', $what, $folder));
    }
}
