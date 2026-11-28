<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
use PHPStan\Reflection\ClassReflection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final readonly class PhpUnitTestMethod
{
    // PHPUnit runs only the methods of a named TestCase subclass, so an interface, a double or an anonymous class may name a method test*() freely
    public static function isTestCase(ClassReflection $class): bool
    {
        return !$class->isInterface() && !$class->isAnonymous() && in_array(TestCase::class, $class->getParentClassesNames(), true);
    }

    public static function isRun(ReflectionMethod $method): bool
    {
        return $method->isPublic() && self::isNamedOrMarked($method);
    }

    public static function isNamedOrMarked(ReflectionMethod $method): bool
    {
        return self::isNamed($method->getName()) || self::isMarked($method);
    }

    public static function isNamed(string $name): bool
    {
        return str_starts_with($name, 'test');
    }

    // because PHPUnit runs a method marked #[Test] as a test even when another method names it as a provider
    public static function isMarked(ReflectionMethod $method): bool
    {
        return [] !== $method->getAttributes(Test::class);
    }
}
