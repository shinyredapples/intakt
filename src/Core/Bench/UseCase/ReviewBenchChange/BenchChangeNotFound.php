<?php

declare(strict_types=1);

namespace Intakt\Core\Bench\UseCase\ReviewBenchChange;

use RuntimeException;

final class BenchChangeNotFound extends RuntimeException
{
    public static function named(string $name): self
    {
        return new self('There is no bench change named "'.$name.'".');
    }
}
