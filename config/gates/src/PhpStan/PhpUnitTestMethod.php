<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
use PHPUnit\Framework\Attributes\Test;

final readonly class PhpUnitTestMethod
{
    public static function isRun(ReflectionMethod $method): bool
    {
        return $method->isPublic() && self::isMarkedOrNamedAsTest($method);
    }

    // because the visibility a class gives a trait method may differ from the one reflection reports, a caller that knows it better checks it itself
    public static function isMarkedOrNamedAsTest(ReflectionMethod $method): bool
    {
        return str_starts_with($method->getName(), 'test') || self::isMarked($method);
    }

    // because PHPUnit runs a method marked #[Test] as a test even when another method names it as a provider
    public static function isMarked(ReflectionMethod $method): bool
    {
        return [] !== $method->getAttributes(Test::class);
    }
}
