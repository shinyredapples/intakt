<?php

declare(strict_types=1);

namespace Intakt\Gates;

use RuntimeException;

final class RepositoryAttributesRefused extends RuntimeException
{
    public static function at(string $file): self
    {
        return new self(sprintf('%s exists: git reads it on top of every other attributes source and no setting switches it off, remove it before the repository is read', $file));
    }
}
