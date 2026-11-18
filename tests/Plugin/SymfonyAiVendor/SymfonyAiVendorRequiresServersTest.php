<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiVendor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionParameter;

#[CoversClass(SymfonyAiVendor::class)]
final class SymfonyAiVendorRequiresServersTest extends TestCase
{
    public function testTheVendorCannotBeBuiltWithoutReviewServerAccesses(): void
    {
        $parameter = $this->serversParameter();

        self::assertFalse($parameter->isOptional());
    }

    public function testTheVendorRejectsNullReviewServerAccesses(): void
    {
        $parameter = $this->serversParameter();

        self::assertFalse($parameter->allowsNull());
    }

    private function serversParameter(): ReflectionParameter
    {
        foreach (new ReflectionMethod(SymfonyAiVendor::class, '__construct')->getParameters() as $parameter) {
            if ('servers' === $parameter->getName()) {
                return $parameter;
            }
        }

        self::fail('The vendor constructor has no servers parameter.');
    }
}
