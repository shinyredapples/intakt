<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Kernel;

#[CoversClass(SymfonyRelease::class)]
final class SymfonyReleaseTest extends TestCase
{
    public function testTheInstalledReleaseIsAtLeastItself(): void
    {
        self::assertTrue(SymfonyRelease::isAtLeast(Kernel::VERSION));
    }

    public function testTheInstalledReleaseIsAtLeastAnEarlierMinor(): void
    {
        self::assertTrue(SymfonyRelease::isAtLeast(Kernel::MAJOR_VERSION.'.0'));
    }

    public function testTheInstalledReleaseIsNotAtLeastTheNextMinorOfItsMajor(): void
    {
        self::assertFalse(SymfonyRelease::isAtLeast(Kernel::MAJOR_VERSION.'.'.(Kernel::MINOR_VERSION + 1)));
    }

    public function testTheInstalledReleaseIsNotAtLeastTheNextMajor(): void
    {
        self::assertFalse(SymfonyRelease::isAtLeast((Kernel::MAJOR_VERSION + 1).'.0'));
    }
}
