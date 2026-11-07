<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Deprecation\Fixtures;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\DebugClassLoader;

#[CoversNothing]
final class LoadsADeprecatedClassThroughTheDebugClassLoaderFixture extends TestCase
{
    public function testItLoadsAClassThatIsDeprecatedWhileTheDebugClassLoaderIsEnabled(): void
    {
        DebugClassLoader::enable();

        try {
            // Because the static analysis of this repository reports a deprecated class named in code, and this fixture must load one.
            $name = implode('\\', ['Symfony', 'Component', 'HttpKernel', 'DependencyInjection', 'ServicesResetterInterface']);

            self::assertTrue(interface_exists($name));
        } finally {
            DebugClassLoader::disable();
        }
    }
}
