<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\UnionType;
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
        if (str_starts_with($scope->getFile(), $this->root.self::TEST_FOLDER) || $this->isValueObjectConstructor($node, $scope)) {
            return [];
        }

        $flags = array_filter($node->getParams(), fn (Param $parameter): bool => $this->isBoolean($parameter->type));

        return array_values(array_map($this->finding(...), $flags));
    }

    // a readonly service is still a service, so a boolean is data only for a readonly class the container does not hold
    private function isValueObjectConstructor(FunctionLike $node, Scope $scope): bool
    {
        $class = $scope->getClassReflection();

        return $node instanceof ClassMethod
            && '__construct' === $node->name->toLowerString()
            && $class instanceof ClassReflection
            && $class->isReadOnly()
            && (!$this->registration->covers($scope->getFile()) || $this->registration->excludes($class));
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

    private function finding(Param $parameter): IdentifierRuleError
    {
        $name = $parameter->var instanceof Variable && is_string($parameter->var->name) ? $parameter->var->name : 'parameter';
        $message = sprintf('Parameter $%s is a flag: model the modes with a backed enum, or split the method in two. A boolean that is data belongs in a readonly value object.', $name);

        return RuleErrorBuilder::message($message)->identifier('intakt.flagParameter')->line($parameter->getStartLine())->build();
    }
}
