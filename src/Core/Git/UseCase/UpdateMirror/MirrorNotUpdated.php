<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\UpdateMirror;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class MirrorNotUpdated extends RuntimeException implements ReportableFailure
{
    public static function at(string $path, string $reason): self
    {
        return new self(sprintf('the mirror at %s was not updated: %s', $path, $reason));
    }
}
