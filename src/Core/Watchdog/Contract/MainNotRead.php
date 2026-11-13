<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Contract;

use RuntimeException;

final class MainNotRead extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self('main could not be read: '.$reason);
    }
}
