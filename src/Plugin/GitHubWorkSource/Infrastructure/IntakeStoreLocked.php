<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Infrastructure;

use RuntimeException;
use Throwable;

final class IntakeStoreLocked extends RuntimeException
{
    public static function after(string $project, int $attempts, ?Throwable $refusal): self
    {
        return new self(sprintf('The stored listing of project "%s" stayed locked by another writer for %d attempts.', $project, $attempts), 0, $refusal);
    }
}
