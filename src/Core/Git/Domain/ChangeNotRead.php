<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Domain;

use RuntimeException;

final class ChangeNotRead extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self('the change could not be read: '.$reason);
    }
}
