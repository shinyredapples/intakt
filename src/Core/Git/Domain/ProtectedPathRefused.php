<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Domain;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class ProtectedPathRefused extends RuntimeException implements ReportableFailure
{
    public static function entry(string $entry): self
    {
        return new self(sprintf('"%s" is not a protected path: name a file or a folder relative to the repository root, without an empty, "." or ".." part', $entry));
    }
}
