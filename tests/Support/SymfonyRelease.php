<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use Symfony\Component\HttpKernel\Kernel;

final readonly class SymfonyRelease
{
    public static function isAtLeast(string $version): bool
    {
        return version_compare(Kernel::VERSION, $version, '>=');
    }
}
