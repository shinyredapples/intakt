<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiVendor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(SymfonyAiVendor::class)]
final class SymfonyAiVendorRequiresServersTest extends TestCase
{
    public function testTheVendorCannotBeBuiltWithoutReviewServerAccesses(): void
    {
        $parameter = new ReflectionMethod(SymfonyAiVendor::class, '__construct')->getParameters()[7];

        self::assertSame('servers', $parameter->getName());
        self::assertFalse($parameter->isOptional());
    }

    public function testTheVendorRejectsNullReviewServerAccesses(): void
    {
        $parameter = new ReflectionMethod(SymfonyAiVendor::class, '__construct')->getParameters()[7];

        self::assertFalse($parameter->allowsNull());
    }
}
