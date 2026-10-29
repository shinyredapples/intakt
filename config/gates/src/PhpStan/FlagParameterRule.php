<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\UnionType;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<FunctionLike>
 */
final readonly class FlagParameterRule implements Rule
{
    private const array BOOLEAN_TYPES = ['bool', 'true', 'false'];

    // a test double or builder may take a flag to stay short, so only code outside tests/ is held to the rule
    private const string TEST_FOLDER = '/tests/';

    private const array SCOPE_READERS = ['compact', 'func_get_arg', 'func_get_args', 'get_defined_vars'];

    public function __construct(private string $root, private ContainerRegistration $registration)
    {
    }

    public function getNodeType(): string
    {
        return FunctionLike::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (str_starts_with($scope->getFile(), $this->root.self::TEST_FOLDER)) {
            return [];
        }

        $flags = array_filter($node->getParams(), fn (Param $parameter): bool => $this->isBoolean($parameter->type) && !$this->isValueObjectData($parameter, $node, $scope));

        return array_values(array_map($this->finding(...), $flags));
    }

    // a readonly service is still a service, so a boolean is data only when a readonly class the container skips keeps it as it is
    private function isValueObjectData(Param $parameter, FunctionLike $node, Scope $scope): bool
    {
        $class = $scope->getClassReflection();

        return $node instanceof ClassMethod
            && '__construct' === $node->name->toLowerString()
            && $class instanceof ClassReflection
            && $class->isReadOnly()
            && $this->registration->excludes($class)
            && $this->isOnlyStored($parameter, $node);
    }

    // a boolean the constructor reads, as $x or as $this->x, is a flag like any other, so the body may touch it only in one plain $this->x = $x
    private function isOnlyStored(Param $parameter, ClassMethod $constructor): bool
    {
        $name = $this->nameOf($parameter);
        $statements = $constructor->stmts ?? [];

        if (null === $name || $this->readsIndirectly($statements)) {
            return false;
        }

        if ($parameter->isPromoted()) {
            return 0 === $this->variableUses($statements, $name) && 0 === $this->propertyUses($statements, $name);
        }

        $property = array_first(array_filter(array_map(fn (Stmt $statement): ?string => $this->storedProperty($statement, $name), $statements), is_string(...)));

        return null !== $property
            && 1 === $this->variableUses($statements, $name)
            && 1 === $this->propertyUses($statements, $property);
    }

    private function storedProperty(Stmt $statement, string $name): ?string
    {
        $assign = $statement instanceof Expression ? $statement->expr : null;

        return $assign instanceof Assign && $this->isOwnProperty($assign->var) && $assign->var->name instanceof Identifier && $assign->expr instanceof Variable && $assign->expr->name === $name
            ? $assign->var->name->toString()
            : null;
    }

    // $this handed on (an alias, a method call) or the local scope read wholesale reaches the boolean out of sight, so either counts as a read
    /**
     * @param array<Stmt> $statements
     */
    private function readsIndirectly(array $statements): bool
    {
        $finder = new NodeFinder();
        $bareThis = count($finder->find($statements, fn (Node $found): bool => $found instanceof Variable && 'this' === $found->name)) - count($finder->find($statements, $this->isOwnProperty(...)));

        return $bareThis > 0 || null !== $finder->findFirst($statements, $this->readsScope(...));
    }

    // a call by computed name could be any of them, so it counts too
    private function readsScope(Node $node): bool
    {
        return $node instanceof FuncCall
            && (!$node->name instanceof Name || in_array($node->name->toLowerString(), self::SCOPE_READERS, true));
    }

    /**
     * @param array<Stmt> $statements
     */
    private function variableUses(array $statements, string $name): int
    {
        return count(new NodeFinder()->find($statements, fn (Node $found): bool => $found instanceof Variable && $this->mayName($found->name, $name)));
    }

    /**
     * @param array<Stmt> $statements
     */
    private function propertyUses(array $statements, string $property): int
    {
        return count(new NodeFinder()->find($statements, fn (Node $found): bool => $this->isOwnProperty($found) && $this->mayName($found->name, $property)));
    }

    /**
     * @phpstan-assert-if-true PropertyFetch|NullsafePropertyFetch $node
     */
    private function isOwnProperty(Node $node): bool
    {
        return ($node instanceof PropertyFetch || $node instanceof NullsafePropertyFetch) && $node->var instanceof Variable && 'this' === $node->var->name;
    }

    // a name computed at run time could be any name, so it counts as a read of the boolean rather than letting a flag through
    private function mayName(string|Identifier|Expr $name, string $wanted): bool
    {
        return match (true) {
            is_string($name) => $name === $wanted,
            $name instanceof Identifier => $name->toString() === $wanted,
            $name instanceof String_ => $name->value === $wanted,
            default => true,
        };
    }

    private function isBoolean(Identifier|Name|ComplexType|null $type): bool
    {
        return match (true) {
            $type instanceof Identifier => in_array($type->toLowerString(), self::BOOLEAN_TYPES, true),
            $type instanceof NullableType => $this->isBoolean($type->type),
            $type instanceof UnionType => array_any($type->types, fn (Identifier|Name|IntersectionType $member): bool => $this->isBoolean($member)),
            default => false,
        };
    }

    private function nameOf(Param $parameter): ?string
    {
        return $parameter->var instanceof Variable && is_string($parameter->var->name) ? $parameter->var->name : null;
    }

    private function finding(Param $parameter): IdentifierRuleError
    {
        $message = sprintf('Parameter $%s is a flag: model the modes with a backed enum, or split the method in two. A boolean that is data is a property a readonly value object marked #[Exclude] stores as it is.', $this->nameOf($parameter) ?? 'parameter');

        return RuleErrorBuilder::message($message)->identifier('intakt.flagParameter')->line($parameter->getStartLine())->build();
    }
}
