<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\TraitUseAdaptation;
use PhpParser\Node\Stmt\TraitUseAdaptation\Alias;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ExtendedParameterReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Psr\Container\ContainerInterface as PsrContainer;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * @implements Rule<FileNode>
 */
final readonly class NoServiceLocatorRule implements Rule
{
    private const array LOCATORS = [PsrContainer::class, ContainerInterface::class, ServiceLocator::class];

    private const string COMPLY = 'no service locator in src/, because services pulled from a container at runtime are hidden from the signature, from static analysis and from the container\'s own checks; inject each service the class needs through its constructor, or a tagged set as an iterable with #[AutowireIterator].';

    public function __construct(private string $root, private ReflectionProvider $reflections)
    {
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // tests boot kernels and read services from the container on purpose, so only the application code is judged
        if (!str_starts_with($scope->getFile(), $this->root.'/src/')) {
            return [];
        }

        $finder = new NodeFinder();
        // a class can also be declared inside a function body or a condition, so the whole file is searched once instead of relying on where PHPStan visits class nodes
        $classes = $finder->findInstanceOf($node->getNodes(), ClassLike::class);
        $locatorAttributes = array_filter($finder->findInstanceOf($node->getNodes(), Attribute::class), static fn (Attribute $attribute): bool => self::isNamed($attribute->name, AutowireLocator::class));

        return [
            ...array_merge(...array_values(array_map($this->locatorParametersOf(...), $classes))),
            ...array_values(array_map($this->locatorAttribute(...), $locatorAttributes)),
        ];
    }

    // compared case-insensitively because PHP matches class names in any letter case
    private static function isNamed(Name $name, string $class): bool
    {
        return 0 === strcasecmp(ltrim($name->toString(), '\\'), $class);
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function locatorParametersOf(ClassLike $class): array
    {
        $located = array_filter([...$this->declaredParametersOf($class), ...$this->aliasedParametersOf($class)], static fn (array $parameter): bool => null !== $parameter[1]);

        return array_values(array_map(fn (array $parameter): IdentifierRuleError => $this->locatorParameter($this->nameOf($class), $parameter), $located));
    }

    /**
     * @return list<array{string, ?string, int}>
     */
    private function declaredParametersOf(ClassLike $class): array
    {
        $constructor = $class->getMethod('__construct');

        return array_values(array_map(fn (Param $parameter): array => [$this->variableOf($parameter), $this->locatorAmong($this->namesIn($parameter)), $parameter->getStartLine()], $constructor instanceof ClassMethod ? $constructor->params : []));
    }

    // a trait method aliased to __construct is the constructor, and it is usually declared in another file, so its parameters are read through reflection
    /**
     * @return list<array{string, ?string, int}>
     */
    private function aliasedParametersOf(ClassLike $class): array
    {
        $parameters = [];

        foreach ($class->getTraitUses() as $traitUse) {
            foreach (array_filter($traitUse->adaptations, $this->aliasesTheConstructor(...)) as $alias) {
                array_push($parameters, ...$this->traitParametersOf($traitUse, $alias));
            }
        }

        return $parameters;
    }

    private function aliasesTheConstructor(TraitUseAdaptation $adaptation): bool
    {
        return $adaptation instanceof Alias && 0 === strcasecmp((string) $adaptation->newName?->toString(), '__construct');
    }

    /**
     * @return list<array{string, ?string, int}>
     */
    private function traitParametersOf(TraitUse $traitUse, TraitUseAdaptation $alias): array
    {
        $method = $this->traitMethodOf($alias->trait instanceof Name ? [$alias->trait] : $traitUse->traits, $alias->method->toString());
        $parameters = $method instanceof ExtendedMethodReflection ? $method->getOnlyVariant()->getParameters() : [];

        return array_map(fn (ExtendedParameterReflection $parameter): array => [$parameter->getName(), $this->locatorAmong($parameter->getNativeType()->getReferencedClasses()), $alias->getStartLine()], $parameters);
    }

    /**
     * @param array<Name> $traits
     */
    private function traitMethodOf(array $traits, string $method): ?ExtendedMethodReflection
    {
        foreach ($traits as $trait) {
            if ($this->reflections->hasClass($trait->toString()) && $this->reflections->getClass($trait->toString())->hasNativeMethod($method)) {
                return $this->reflections->getClass($trait->toString())->getNativeMethod($method);
            }
        }

        return null;
    }

    // the anonymous class has no name node, and PHP-Parser leaves its namespaced name uninitialized, so it is checked before it is read
    private function nameOf(ClassLike $class): string
    {
        return $class->name instanceof Identifier ? ($class->namespacedName ?? $class->name)->toString() : 'class@anonymous';
    }

    private function variableOf(Param $parameter): string
    {
        return $parameter->var instanceof Variable && is_string($parameter->var->name) ? $parameter->var->name : '';
    }

    // searched as nested names because a nullable, union or intersection type wraps them, and taken as written because PHPStan's parser has already resolved each against the imports, so an alias still counts
    /**
     * @return list<string>
     */
    private function namesIn(Param $parameter): array
    {
        return $parameter->type instanceof Node ? array_values(array_map(static fn (Name $name): string => $name->toString(), new NodeFinder()->findInstanceOf($parameter->type, Name::class))) : [];
    }

    /**
     * @param list<string> $classes
     */
    private function locatorAmong(array $classes): ?string
    {
        return array_find(self::LOCATORS, static fn (string $locator): bool => array_any($classes, static fn (string $class): bool => 0 === strcasecmp(ltrim($class, '\\'), $locator)));
    }

    /**
     * @param array{string, ?string, int} $parameter
     */
    private function locatorParameter(string $class, array $parameter): IdentifierRuleError
    {
        [$variable, $locator, $line] = $parameter;
        $message = sprintf('Constructor of %s takes $%s as a service locator (%s): %s', $class, $variable, (string) $locator, self::COMPLY);

        return RuleErrorBuilder::message($message)->identifier('intakt.noServiceLocator')->line($line)->build();
    }

    private function locatorAttribute(Attribute $attribute): IdentifierRuleError
    {
        $message = sprintf('Attribute #[AutowireLocator] injects a service locator: %s', self::COMPLY);

        return RuleErrorBuilder::message($message)->identifier('intakt.noServiceLocator')->line($attribute->getStartLine())->build();
    }
}
