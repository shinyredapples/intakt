<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class RoundNotNext extends RuntimeException implements ReportableFailure
{
    public static function for(int $asked, int $next): self
    {
        return new self('Round '.$asked.' was asked for, but the next round of this change is round '.$next.'.');
    }
}
