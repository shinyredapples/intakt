<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionClass;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionEnum;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
use PHPStan\Collectors\Collector;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

/**
 * @implements Collector<InClassNode, list<array{string, string}>>
 */
final readonly class TestProviderCollector implements Collector
{
    public function __construct(private ReflectionProvider $reflections)
    {
    }

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @return list<array{string, string}>|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $class = $node->getClassReflection();

        // PHPUnit runs only concrete named TestCase subclasses, so an abstract base's test counts through each subclass that inherits it without overriding it
        if ($class->isAbstract() || $class->isAnonymous() || !in_array(TestCase::class, $class->getParentClassesNames(), true)) {
            return null;
        }

        $native = $class->getNativeReflection();
        $providers = [];

        foreach ($native->getMethods() as $method) {
            // because PHPUnit ignores a provider attribute on a method it does not run as a test, such as a private helper
            if (PhpUnitTestMethod::isRun($method)) {
                $providers = [...$providers, ...$this->providersInTheClass($method, $native), ...$this->externalProviders($method)];
            }
        }

        return [] === $providers ? null : $providers;
    }

    /**
     * @return list<array{string, string}>
     */
    private function providersInTheClass(ReflectionMethod $method, ReflectionClass|ReflectionEnum $class): array
    {
        $providers = [];

        foreach ($method->getAttributes(DataProvider::class) as $attribute) {
            $arguments = $attribute->getArguments();
            $methodName = $arguments['methodName'] ?? $arguments[0] ?? null;

            if (is_string($methodName)) {
                $providers = [...$providers, ...$this->declarations($class, $methodName)];
            }
        }

        return $providers;
    }

    /**
     * @return list<array{string, string}>
     */
    private function externalProviders(ReflectionMethod $method): array
    {
        $providers = [];

        foreach ($method->getAttributes(DataProviderExternal::class) as $attribute) {
            $arguments = $attribute->getArguments();
            $className = $arguments['className'] ?? $arguments[0] ?? null;
            $methodName = $arguments['methodName'] ?? $arguments[1] ?? null;

            if (is_string($className) && is_string($methodName)) {
                $providers = [...$providers, ...($this->reflections->hasClass($className) ? $this->declarations($this->reflections->getClass($className)->getNativeReflection(), $methodName) : [[ltrim($className, '\\'), $methodName]])];
            }
        }

        return $providers;
    }

    // the class that declares the method PHPUnit calls, because a provider a parent class or a trait declares is one declaration however many subclasses name it, and each abstract declaration it implements, which PHPUnit never runs
    /**
     * @return list<array{string, string}>
     */
    private function declarations(ReflectionClass|ReflectionEnum $class, string $methodName): array
    {
        $declarations = [[$class->hasMethod($methodName) ? $class->getMethod($methodName)->getDeclaringClass()->getName() : $class->getName(), $methodName]];

        for ($parent = $class->getParentClass(); false !== $parent; $parent = $parent->getParentClass()) {
            if ($parent->hasMethod($methodName) && $parent->getMethod($methodName)->isAbstract()) {
                $declarations[] = [$parent->getMethod($methodName)->getDeclaringClass()->getName(), $methodName];
            }
        }

        return $declarations;
    }
}
