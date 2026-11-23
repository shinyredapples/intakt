<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\UseCase\ReplayDay;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class ScriptLineNotUnderstood extends RuntimeException implements ReportableFailure
{
    public static function because(string $line, string $rule): self
    {
        return new self(sprintf('The script line "%s" is not understood: %s.', trim($line), $rule));
    }
}
