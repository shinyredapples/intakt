<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\Contract;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class ProjectHasNoRepository extends RuntimeException implements ReportableFailure
{
    public static function named(string $project): self
    {
        return new self(sprintf('Project "%s" has no watch section with a repository.', $project));
    }
}
