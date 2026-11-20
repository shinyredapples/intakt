<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Infrastructure;

use RuntimeException;
use Throwable;

final class IntakeStoreLocked extends RuntimeException
{
    /**
     * @param list<Throwable> $refusals
     */
    public static function after(string $project, array $refusals): self
    {
        return new self(sprintf('The stored listing of project "%s" stayed locked by another writer for %d attempts.', $project, count($refusals)), 0, array_last($refusals));
    }
}
