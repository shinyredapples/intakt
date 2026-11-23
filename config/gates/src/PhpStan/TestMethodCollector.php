<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionAttribute;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
use PHPStan\Collectors\Collector;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Reflection\ClassReflection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

/**
 * @implements Collector<InClassMethodNode, array{misnamed: array{string, int, string, string, string}|null, external: list<array{string, string}>}>
 */
final readonly class TestMethodCollector implements Collector
{
    // an acronym or a capital with the lowercase letters and digits after it is one word, so HTTPRequest and Its192Px are two words each
    private const string WORD = '[A-Z]{2,}(?![a-z])[0-9]*|[A-Z][a-z0-9]*';

    private const int SENTENCE_WORDS = 3;

    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    /**
     * @return array{misnamed: array{string, int, string, string, string}|null, external: list<array{string, string}>}|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $class = $node->getClassReflection();

        // PHPUnit runs only the methods of a TestCase subclass, so an interface or a double may name a method test*() freely
        if ($class->isInterface() || !in_array(TestCase::class, $class->getParentClassesNames(), true)) {
            return null;
        }

        $method = $class->getNativeReflection()->getMethod($node->getOriginalNode()->name->toString());
        $external = $this->externalProviders($method);
        $misnamed = $this->isMisnamedTest($method, $class) ? $this->location($node, $scope) : null;

        return null === $misnamed && [] === $external ? null : ['misnamed' => $misnamed, 'external' => $external];
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
                $providers[] = [ltrim($className, '\\'), $methodName];
            }
        }

        return $providers;
    }

    private function isMisnamedTest(ReflectionMethod $method, ClassReflection $class): bool
    {
        $name = $method->getName();

        return $method->isPublic() && str_starts_with($name, 'test') && !$this->readsAsSentence(substr($name, 4)) && !$this->isNamedProvider($name, $class);
    }

    /**
     * @return array{string, int, string, string, string}
     */
    private function location(InClassMethodNode $node, Scope $scope): array
    {
        $method = $node->getOriginalNode();
        $trait = $scope->getTraitReflection();
        $file = $trait?->getFileName() ?? $scope->getFile();

        return [$file, $method->name->getStartLine(), $trait?->getName() ?? $node->getClassReflection()->getName(), $node->getClassReflection()->getName(), $method->name->toString()];
    }

    private function readsAsSentence(string $words): bool
    {
        return 1 === preg_match('~^(?:'.self::WORD.')+$~', $words) && self::SENTENCE_WORDS <= preg_match_all('~'.self::WORD.'~', $words);
    }

    // a provider may be named by a parent, an interface or a trait and implemented here, as an abstract test case leaves its cases to each subclass
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
