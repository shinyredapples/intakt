<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionAttribute;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionClass;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionEnum;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
use PHPStan\Collectors\Collector;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @implements Collector<InClassMethodNode, array{misnamed: array{string, int, string, string, string, bool}|null, providers: list<array{string, string}>}>
 */
final readonly class TestMethodCollector implements Collector
{
    // an acronym or a capital with the lowercase letters and digits after it is one word, so HTTPRequest and Its192Px are two words each; a run of capitals stays one word even when it starts with A or I, because only a dictionary could tell an article in AURL from the acronym ASCII
    private const string WORD = '[A-Z]{2,}(?![a-z])[0-9]*|[A-Z][a-z0-9]*';

    private const int SENTENCE_WORDS = 3;

    public function __construct(private ReflectionProvider $reflections)
    {
    }

    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    /**
     * @return array{misnamed: array{string, int, string, string, string, bool}|null, providers: list<array{string, string}>}|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $class = $node->getClassReflection();

        // PHPUnit runs only the methods of a named TestCase subclass, so an interface, a double or an anonymous class may name a method test*() freely
        if ($class->isInterface() || $class->isAnonymous() || !in_array(TestCase::class, $class->getParentClassesNames(), true)) {
            return null;
        }

        $method = $class->getNativeReflection()->getMethod($node->getOriginalNode()->name->toString());
        $providers = [...$this->providersInTheClass($method, $class->getNativeReflection()), ...$this->externalProviders($method)];
        $misnamed = $this->isMisnamedTest($method, $class) ? $this->location($node, $scope, $method) : null;

        return null === $misnamed && [] === $providers ? null : ['misnamed' => $misnamed, 'providers' => $providers];
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
                $providers[] = $this->declaration($class, $methodName);
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
                $providers[] = $this->reflections->hasClass($className) ? $this->declaration($this->reflections->getClass($className)->getNativeReflection(), $methodName) : [ltrim($className, '\\'), $methodName];
            }
        }

        return $providers;
    }

    private function isMisnamedTest(ReflectionMethod $method, ClassReflection $class): bool
    {
        $name = $method->getName();
        $isTest = str_starts_with($name, 'test') || [] !== $method->getAttributes(Test::class);

        return $method->isPublic() && $isTest && (!str_starts_with($name, 'test') || !$this->readsAsSentence(substr($name, 4))) && !$this->isNamedProvider($name, $class);
    }

    /**
     * @return array{string, int, string, string, string, bool}
     */
    private function location(InClassMethodNode $node, Scope $scope, ReflectionMethod $method): array
    {
        $trait = $scope->getTraitReflection();
        $file = $trait?->getFileName() ?? $scope->getFile();

        return [$file, $node->getOriginalNode()->name->getStartLine(), $trait?->getName() ?? $node->getClassReflection()->getName(), $method->getDeclaringClass()->getName(), $method->getName(), $this->mayBeProvider($node, $method)];
    }

    // PHPUnit wants a provider static and returning an iterable, so a static method declared to return a type that cannot be iterated, such as void, can only be a test
    private function mayBeProvider(InClassMethodNode $node, ReflectionMethod $method): bool
    {
        return $method->isStatic() && !$node->getMethodReflection()->getNativeReturnType()->isIterable()->no();
    }

    // the class that declares the named method, because a provider a parent class or a trait declares is one declaration however many subclasses name it
    /**
     * @return array{string, string}
     */
    private function declaration(ReflectionClass|ReflectionEnum $class, string $methodName): array
    {
        return $class->hasMethod($methodName) ? [$class->getMethod($methodName)->getDeclaringClass()->getName(), $methodName] : [$class->getName(), $methodName];
    }

    private function readsAsSentence(string $words): bool
    {
        return 1 === preg_match('~^(?:'.self::WORD.')+$~', $words) && self::SENTENCE_WORDS <= preg_match_all('~'.self::WORD.'~', $words);
    }

    // checked up the hierarchy because an abstract test case may name a provider that only each subclass implements
    private function isNamedProvider(string $name, ClassReflection $class): bool
    {
        $declarers = [$class, ...$class->getParents(), ...$class->getInterfaces()];

        return array_any($declarers, fn (ClassReflection $declarer): bool => array_any($declarer->getNativeReflection()->getMethods(), fn (ReflectionMethod $method): bool => $this->namesProvider($method, $name)));
    }

    private function namesProvider(ReflectionMethod $method, string $name): bool
    {
        return array_any($method->getAttributes(DataProvider::class), static function (ReflectionAttribute $attribute) use ($name): bool {
            $arguments = $attribute->getArguments();
            $provider = $arguments['methodName'] ?? $arguments[0] ?? null;

            return is_string($provider) && 0 === strcasecmp($provider, $name);
        });
    }
}
