<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\UseCase\ReplayDay;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class ScriptHoldsNoStep extends RuntimeException implements ReportableFailure
{
    public static function create(): self
    {
        return new self('The script holds no step.');
    }
}
