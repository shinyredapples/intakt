<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Deprecation\Fixtures;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class LoadsADeprecatedClassFixture extends TestCase
{
    public function testItLoadsAClassThatIsDeprecatedInTheVendorPackage(): void
    {
        self::assertTrue(interface_exists('Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface'));
    }
}
