<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use Symfony\Component\HttpKernel\Kernel;

// Lets a test hold an outcome that differs between the Symfony releases the lock may carry while the move from one to the next is under way.
final readonly class SymfonyRelease
{
    public static function isAtLeast(string $version): bool
    {
        return version_compare(Kernel::VERSION, $version, '>=');
    }
}
