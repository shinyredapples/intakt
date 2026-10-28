<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use RuntimeException;

final class ChangeNotFound extends RuntimeException
{
    public static function named(ChangeId $id): self
    {
        return new self(sprintf('The forge has no change "%s".', $id->value));
    }
}
