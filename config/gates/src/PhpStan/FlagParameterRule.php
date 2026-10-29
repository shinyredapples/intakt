<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr;
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

    // a boolean the constructor reads to decide something is a flag like any other, so the body may use it only in one plain $this->x = $x
    private function isOnlyStored(Param $parameter, ClassMethod $constructor): bool
    {
        $name = $this->nameOf($parameter);
        $statements = $constructor->stmts ?? [];
        $uses = new NodeFinder()->find($statements, static fn (Node $found): bool => $found instanceof Variable && $found->name === $name);

        if ($parameter->isPromoted()) {
            return [] === $uses;
        }

        return 1 === count($uses)
            && array_any($statements, fn (Stmt $statement): bool => $statement instanceof Expression && $this->storesUnchanged($statement->expr, $name));
    }

    private function storesUnchanged(Expr $expression, ?string $name): bool
    {
        return null !== $name
            && $expression instanceof Assign
            && $expression->var instanceof PropertyFetch
            && $expression->var->var instanceof Variable
            && 'this' === $expression->var->var->name
            && $expression->expr instanceof Variable
            && $expression->expr->name === $name;
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
