<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\ListProtectedChanges;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class CommitNameRefused extends RuntimeException implements ReportableFailure
{
    public static function notFull(string $head): self
    {
        return new self(sprintf('"%s" is not a full commit name', $head));
    }
}
