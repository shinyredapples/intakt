<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Type\Constant\ConstantStringType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @implements Collector<ClassMethod, array{file: string, line: int, name: string, method: string, runnable: bool, provides: list<string>, overrides: list<string>}>
 */
final readonly class TestMethodCollector implements Collector
{
    public const string PREFIX = 'test';

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    /**
     * @return array{file: string, line: int, name: string, method: string, runnable: bool, provides: list<string>, overrides: list<string>}|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $class = $scope->getClassReflection();
        // PHPStan analyzes a trait's method once inside each class that uses the trait, so a test a class imports is seen here as a method of that class, declared in the trait's file
        $file = $scope->isInTrait() ? $scope->getTraitReflection()->getFileName() : $scope->getFile();

        if (!$class instanceof ClassReflection || null === $file) {
            return null;
        }

        $name = $node->name->toString();
        $runnable = $this->isTestCase($class) && $node->isPublic() && (str_starts_with($name, self::PREFIX) || [] !== $this->attributesOf($node, $scope, Test::class));
        $provides = $this->providersNamedOn($node, $scope, $class);

        if (!$runnable && [] === $provides) {
            return null;
        }

        return ['file' => $file, 'line' => $node->name->getStartLine(), 'name' => $name, 'method' => $class->getName().'::'.$name, 'runnable' => $runnable, 'provides' => $provides, 'overrides' => $runnable ? $this->ancestorMethods($class, $name) : []];
    }

    // because PHPUnit runs only the methods of a TestCase, so a test-prefixed method an interface or a test double declares is no test
    private function isTestCase(ClassReflection $class): bool
    {
        return !$class->isInterface() && in_array(TestCase::class, $class->getParentClassesNames(), true);
    }

    /**
     * @return list<string>
     */
    private function ancestorMethods(ClassReflection $class, string $name): array
    {
        $ancestors = [...$class->getParents(), ...$class->getInterfaces()];

        foreach ([$class, ...$class->getParents()] as $user) {
            array_push($ancestors, ...array_values($user->getTraits()));
        }

        return array_values(array_unique(array_map(static fn (ClassReflection $ancestor): string => $ancestor->getName().'::'.$name, $ancestors)));
    }

    /**
     * @param class-string $attributeClass
     *
     * @return list<Attribute>
     */
    private function attributesOf(ClassMethod $method, Scope $scope, string $attributeClass): array
    {
        $found = [];

        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                // resolved against the file's imports, so an attribute of another namespace with the same short name is not taken for PHPUnit's
                if (strtolower($attributeClass) === strtolower($scope->resolveName($attribute->name))) {
                    $found[] = $attribute;
                }
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function providersNamedOn(ClassMethod $method, Scope $scope, ClassReflection $class): array
    {
        $provides = [];

        foreach ($this->attributesOf($method, $scope, DataProvider::class) as $attribute) {
            array_push($provides, ...$this->methodsOf([$class], $this->argument($attribute, 0, 'methodName'), $scope));
        }

        foreach ($this->attributesOf($method, $scope, DataProviderExternal::class) as $attribute) {
            $className = $this->argument($attribute, 0, 'className');
            $classes = $className instanceof Expr ? $scope->getType($className)->getClassStringObjectType()->getObjectClassReflections() : [];
            array_push($provides, ...$this->methodsOf($classes, $this->argument($attribute, 1, 'methodName'), $scope));
        }

        return $provides;
    }

    private function argument(Attribute $attribute, int $position, string $name): ?Expr
    {
        foreach ($attribute->args as $index => $argument) {
            if ($name === $argument->name?->toString() || (null === $argument->name && $position === $index)) {
                return $argument->value;
            }
        }

        return null;
    }

    /**
     * @param array<ClassReflection> $classes
     *
     * @return list<string>
     */
    private function methodsOf(array $classes, ?Expr $methodName, Scope $scope): array
    {
        $names = $methodName instanceof Expr ? array_map(static fn (ConstantStringType $name): string => $name->getValue(), $scope->getType($methodName)->getConstantStrings()) : [];
        $methods = [];

        foreach ($classes as $class) {
            foreach ($names as $name) {
                if ($class->hasNativeMethod($name)) {
                    $method = $class->getNativeMethod($name);
                    // named by the class that declares it, the way a judged method is, so a provider a parent class or an imported trait declares is found
                    $methods[] = $method->getDeclaringClass()->getName().'::'.$method->getName();
                } else {
                    // PHPUnit resolves the name on the concrete test class, so a subclass that implements it overrides this name
                    $methods[] = $class->getName().'::'.$name;
                }
            }
        }

        return $methods;
    }
}
