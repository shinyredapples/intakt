<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

use RuntimeException;

final class ChangeNotShown extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
