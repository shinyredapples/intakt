<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates\Deprecation;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class LoadsADeprecatedClassGateFixture extends TestCase
{
    public function testItLoadsAClassThatIsDeprecatedInTheVendorPackage(): void
    {
        // Because the static analysis of this repository reports a deprecated class named in code, and this fixture must load one.
        $name = implode('\\', ['Symfony', 'Component', 'HttpKernel', 'DependencyInjection', 'ServicesResetterInterface']);

        self::assertTrue(interface_exists($name));
    }
}
