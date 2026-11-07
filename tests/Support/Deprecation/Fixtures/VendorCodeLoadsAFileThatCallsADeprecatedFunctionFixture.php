<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Deprecation\Fixtures;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Resource\ClassExistenceResource;

#[CoversNothing]
final class VendorCodeLoadsAFileThatCallsADeprecatedFunctionFixture extends TestCase
{
    public function testVendorCodeAsksForAnIntaktFileWhoseLoadTimeCodeCallsADeprecatedVendorFunction(): void
    {
        $package = DeprecatedVendorPackage::writtenOutsideTheCheckout();

        try {
            self::assertTrue(new ClassExistenceResource(CallsADeprecatedVendorFunctionWhileLoading::class)->isFresh(0));
        } finally {
            $package->remove();
        }
    }
}
