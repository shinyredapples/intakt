<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\Contract;

use RuntimeException;

final class ProjectNamesNoEligibilityLabel extends RuntimeException
{
    public static function named(string $project): self
    {
        return new self(sprintf('Project "%s" has no watch section, so it names no eligibility label.', $project));
    }
}
