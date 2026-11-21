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
use PhpParser\Node\Stmt\TraitUseAdaptation\Alias;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
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

    /**
     * @return list<IdentifierRuleError>
     */
    private function locatorParametersOf(ClassLike $class): array
    {
        $findings = [];
        $constructor = $class->getMethod('__construct');

        foreach ($constructor instanceof ClassMethod ? $constructor->params : [] as $parameter) {
            $locator = $this->locatorTypeOf($parameter);

            if (null !== $locator) {
                $variable = $parameter->var instanceof Variable && is_string($parameter->var->name) ? $parameter->var->name : '';
                $findings[] = $this->locatorParameter(self::nameOf($class), $variable, $locator, $parameter->getStartLine());
            }
        }

        foreach ($class->getTraitUses() as $traitUse) {
            foreach ($traitUse->adaptations as $adaptation) {
                if ($adaptation instanceof Alias && 0 === strcasecmp((string) $adaptation->newName?->toString(), '__construct')) {
                    array_push($findings, ...$this->aliasedLocatorParametersOf($class, $traitUse, $adaptation));
                }
            }
        }

        return $findings;
    }

    // the anonymous class has no name node, and PHP-Parser leaves its namespaced name uninitialized, so it is checked before it is read
    private static function nameOf(ClassLike $class): string
    {
        return $class->name instanceof Identifier ? ($class->namespacedName ?? $class->name)->toString() : 'class@anonymous';
    }

    // a trait method aliased to __construct is the constructor, and it is usually declared in another file, so its parameters are read through reflection
    /**
     * @return list<IdentifierRuleError>
     */
    private function aliasedLocatorParametersOf(ClassLike $class, TraitUse $traitUse, Alias $alias): array
    {
        $method = $alias->method->toString();
        $traits = $alias->trait instanceof Name ? [$alias->trait] : $traitUse->traits;
        $findings = [];

        foreach ($traits as $trait) {
            if (!$this->reflections->hasClass($trait->toString()) || !$this->reflections->getClass($trait->toString())->hasNativeMethod($method)) {
                continue;
            }

            foreach ($this->reflections->getClass($trait->toString())->getNativeMethod($method)->getVariants() as $variant) {
                foreach ($variant->getParameters() as $parameter) {
                    $locator = $this->locatorAmong($parameter->getNativeType()->getReferencedClasses());

                    if (null !== $locator) {
                        $findings[] = $this->locatorParameter(self::nameOf($class), $parameter->getName(), $locator, $alias->getStartLine());
                    }
                }
            }

            break;
        }

        return $findings;
    }

    /**
     * @param list<string> $classes
     */
    private function locatorAmong(array $classes): ?string
    {
        foreach ($classes as $class) {
            foreach (self::LOCATORS as $locator) {
                if (0 === strcasecmp($class, $locator)) {
                    return $locator;
                }
            }
        }

        return null;
    }

    private function locatorTypeOf(Param $parameter): ?string
    {
        if (!$parameter->type instanceof Node) {
            return null;
        }

        // searched as nested names because a nullable, union or intersection type wraps them, and compared as written because PHPStan's parser has already resolved each against the imports, so an alias still counts
        foreach (new NodeFinder()->findInstanceOf($parameter->type, Name::class) as $name) {
            foreach (self::LOCATORS as $locator) {
                if (self::isNamed($name, $locator)) {
                    return $locator;
                }
            }
        }

        return null;
    }

    // compared case-insensitively because PHP matches class names in any letter case
    private static function isNamed(Name $name, string $class): bool
    {
        return 0 === strcasecmp(ltrim($name->toString(), '\\'), $class);
    }

    private function locatorParameter(string $class, string $variable, string $locator, int $line): IdentifierRuleError
    {
        $message = sprintf('Constructor of %s takes $%s as a service locator (%s): %s', $class, $variable, $locator, self::COMPLY);

        return RuleErrorBuilder::message($message)->identifier('intakt.noServiceLocator')->line($line)->build();
    }

    private function locatorAttribute(Attribute $attribute): IdentifierRuleError
    {
        $message = sprintf('Attribute #[AutowireLocator] injects a service locator: %s', self::COMPLY);

        return RuleErrorBuilder::message($message)->identifier('intakt.noServiceLocator')->line($attribute->getStartLine())->build();
    }
}
