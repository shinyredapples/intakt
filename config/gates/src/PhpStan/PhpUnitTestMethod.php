<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
use PHPUnit\Framework\Attributes\Test;

final readonly class PhpUnitTestMethod
{
    public static function isRun(ReflectionMethod $method): bool
    {
        return $method->isPublic() && (str_starts_with($method->getName(), 'test') || self::isMarked($method));
    }

    // because PHPUnit runs a method marked #[Test] as a test even when another method names it as a provider
    public static function isMarked(ReflectionMethod $method): bool
    {
        return [] !== $method->getAttributes(Test::class);
    }
}
