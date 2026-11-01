<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\Contract;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class RepositoryNotFound extends RuntimeException implements ReportableFailure
{
    public static function at(string $path): self
    {
        return new self(sprintf('The repository "%s" is not a directory.', $path));
    }
}
