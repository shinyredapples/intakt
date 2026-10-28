<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use RuntimeException;

final class FileNotFoundAtHead extends RuntimeException
{
    public static function at(string $path, CommitId $head): self
    {
        return new self(sprintf('There is no file "%s" at %s.', $path, $head->hash));
    }
}
