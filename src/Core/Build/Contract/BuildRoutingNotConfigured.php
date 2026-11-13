<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class BuildRoutingNotConfigured extends RuntimeException implements ReportableFailure
{
    public function __construct(string $project)
    {
        parent::__construct(sprintf('Project "%s" is not configured.', $project));
    }
}
