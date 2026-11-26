<?php

declare(strict_types=1);

namespace Intakt\Core\Disk\Contract;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

// Reportable, because a step that asks for room must fail visibly with this message, never crash, when the disk cannot be measured.
final class FreeSpaceUnreadable extends RuntimeException implements ReportableFailure
{
    public static function of(string $folder, string $what): self
    {
        return new self(sprintf('the free %s of "%s" could not be read', $what, $folder));
    }
}
