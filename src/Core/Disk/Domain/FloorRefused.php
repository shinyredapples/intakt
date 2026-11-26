<?php

declare(strict_types=1);

namespace Intakt\Core\Disk\Domain;

use InvalidArgumentException;

final class FloorRefused extends InvalidArgumentException
{
    public static function belowOne(string $name, int $value): self
    {
        return new self(sprintf('the %s floor must be at least 1, %d given', $name, $value));
    }
}
