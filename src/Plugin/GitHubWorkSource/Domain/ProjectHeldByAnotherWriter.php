<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

use RuntimeException;
use Throwable;

// Not a lost write: the other writer has not committed and may still roll back, so nothing says yet which listing is the newer one.
final class ProjectHeldByAnotherWriter extends RuntimeException
{
    public static function of(string $project, Throwable $refusal): self
    {
        return new self(sprintf('Another writer holds the intake rows of project "%s" and has not committed them.', $project), 0, $refusal);
    }
}
