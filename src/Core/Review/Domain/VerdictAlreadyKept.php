<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use LogicException;

final class VerdictAlreadyKept extends LogicException
{
    public static function forKey(string $key): self
    {
        return new self(sprintf('A verdict is kept for the finding key %s already; one finding never holds two.', $key));
    }
}
