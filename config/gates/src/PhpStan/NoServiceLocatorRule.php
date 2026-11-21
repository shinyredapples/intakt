<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
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

    public function __construct(private string $root)
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
            ...array_values(array_map(self::locatorAttribute(...), $locatorAttributes)),
        ];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function locatorParametersOf(ClassLike $class): array
    {
        $constructor = $class->getMethod('__construct');

        if (!$constructor instanceof ClassMethod) {
            return [];
        }

        $findings = [];

        foreach ($constructor->params as $parameter) {
            $locator = $this->locatorTypeOf($parameter);

            if (null !== $locator) {
                $findings[] = self::locatorParameter($class->namespacedName?->toString() ?? 'class@anonymous', $parameter, $locator);
            }
        }

        return $findings;
    }

    private function locatorTypeOf(Param $parameter): ?string
    {
        if (null === $parameter->type) {
            return null;
        }

        // a nullable, union or intersection type holds its class names as nested names, and PHPStan's parser has already resolved each against the imports
        foreach (new NodeFinder()->findInstanceOf($parameter->type, Name::class) as $name) {
            foreach (self::LOCATORS as $locator) {
                if (self::isNamed($name, $locator)) {
                    return $locator;
                }
            }
        }

        return null;
    }

    // PHP matches class names in any letter case
    private static function isNamed(Name $name, string $class): bool
    {
        return 0 === strcasecmp(ltrim($name->toString(), '\\'), $class);
    }

    private static function locatorParameter(string $class, Param $parameter, string $locator): IdentifierRuleError
    {
        $variable = $parameter->var instanceof Variable && is_string($parameter->var->name) ? $parameter->var->name : '';
        $message = sprintf('Constructor of %s takes $%s as a service locator (%s): %s', $class, $variable, $locator, self::COMPLY);

        return RuleErrorBuilder::message($message)->identifier('intakt.noServiceLocator')->line($parameter->getStartLine())->build();
    }

    private static function locatorAttribute(Attribute $attribute): IdentifierRuleError
    {
        $message = sprintf('Attribute #[AutowireLocator] injects a service locator: %s', self::COMPLY);

        return RuleErrorBuilder::message($message)->identifier('intakt.noServiceLocator')->line($attribute->getStartLine())->build();
    }
}
