<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Domain;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class ProjectNameRefused extends RuntimeException implements ReportableFailure
{
    public static function notAFolderName(string $project): self
    {
        return new self(sprintf('"%s" is not a project name that can be a folder name', $project));
    }
}
