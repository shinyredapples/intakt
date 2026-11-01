<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class GlobCannotBeEvaluated extends RuntimeException implements ReportableFailure
{
    public function __construct(string $glob, string $reason)
    {
        parent::__construct(sprintf('The path glob "%s" cannot be evaluated: %s.', $glob, $reason));
    }
}
