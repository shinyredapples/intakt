<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Deprecation\Fixtures;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class LoadsAVendorFileThatCallsADeprecatedFunctionFixture extends TestCase
{
    public function testTheTestAsksForAVendorClassWhoseLoadTimeCodeCallsAnotherVendorsDeprecatedFunction(): void
    {
        $package = DeprecatedVendorPackage::writtenOutsideTheCheckout();
        $composer = array_first(ClassLoader::getRegisteredLoaders());
        self::assertInstanceOf(ClassLoader::class, $composer);
        $composer->addClassMap([DeprecatedVendorPackage::CONSUMER => $package->consumerFile()]);

        try {
            self::assertTrue(class_exists(DeprecatedVendorPackage::CONSUMER));
        } finally {
            $package->remove();
        }
    }
}
