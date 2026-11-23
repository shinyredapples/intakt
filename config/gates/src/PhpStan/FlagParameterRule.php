<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
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

    private function isBoolean(Identifier|Name|ComplexType|null $type): bool
    {
        return match (true) {
            $type instanceof Identifier => in_array($type->toLowerString(), self::BOOLEAN_TYPES, true),
            $type instanceof NullableType => $this->isBoolean($type->type),
            $type instanceof UnionType => array_any($type->types, fn (Identifier|Name|IntersectionType $member): bool => $this->isBoolean($member)),
            default => false,
        };
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
            && $this->isOnlyStored($parameter, $node, $class);
    }

    // any call, eval or include can read the arguments out of sight (a backtrace, an exception trace, the local scope), and naming every way never ends, so a boolean is kept as it is only by a body of plain $this->x = $y statements that hands $x to none or exactly one of them
    private function isOnlyStored(Param $parameter, ClassMethod $constructor, ClassReflection $class): bool
    {
        $name = $this->nameOf($parameter);
        $statements = $constructor->stmts ?? [];

        if (null === $name || !array_all($statements, fn (Stmt $statement): bool => $this->isPlainAssignment($statement, $class))) {
            return false;
        }

        return ($parameter->isPromoted() ? 0 : 1) === $this->variableUses($statements, $name);
    }

    private function nameOf(Param $parameter): ?string
    {
        return $parameter->var instanceof Variable && is_string($parameter->var->name) ? $parameter->var->name : null;
    }

    private function isPlainAssignment(Stmt $statement, ClassReflection $class): bool
    {
        $assign = $statement instanceof Expression ? $statement->expr : null;

        return $assign instanceof Assign
            && $assign->var instanceof PropertyFetch
            && $assign->var->var instanceof Variable
            && 'this' === $assign->var->var->name
            && $assign->var->name instanceof Identifier
            && $this->declares($class, $assign->var->name->toString())
            && $assign->expr instanceof Variable
            && is_string($assign->expr->name);
    }

    // only a property the class declares itself, since PHP hands any other assignment (undeclared, or private to a parent) to __set(), which can read the value
    private function declares(ClassReflection $class, string $property): bool
    {
        return $class->hasNativeProperty($property)
            && $class->getNativeProperty($property)->getDeclaringClass()->getName() === $class->getName();
    }

    /**
     * @param array<Stmt> $statements
     */
    private function variableUses(array $statements, string $name): int
    {
        return count(new NodeFinder()->find($statements, fn (Node $found): bool => $found instanceof Variable && $found->name === $name));
    }

    private function finding(Param $parameter): IdentifierRuleError
    {
        $message = sprintf('Parameter $%s is a flag: model the modes with a backed enum, or split the method in two. A boolean that is data is a property a readonly value object marked #[Exclude] stores as it is.', $this->nameOf($parameter) ?? 'parameter');

        return RuleErrorBuilder::message($message)->identifier('intakt.flagParameter')->line($parameter->getStartLine())->build();
    }
}
