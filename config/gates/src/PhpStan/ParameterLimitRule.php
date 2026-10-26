<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<FunctionLike>
 */
final readonly class ParameterLimitRule implements Rule
{
    private const int LIMIT = 3;

    public function getNodeType(): string
    {
        return FunctionLike::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($this->isConstructor($node)) {
            return [];
        }

        $count = count($node->getParams());

        if ($count <= self::LIMIT) {
            return [];
        }

        $message = sprintf(
            '%s takes %d parameters, at most %d allowed: split it or group values that form a real concept, never an artificial parameter object.',
            $this->describe($node),
            $count,
            self::LIMIT,
        );

        return [RuleErrorBuilder::message($message)->identifier('intakt.parameterLimit')->build()];
    }

    private function isConstructor(FunctionLike $node): bool
    {
        return $node instanceof ClassMethod && '__construct' === $node->name->toLowerString();
    }

    private function describe(FunctionLike $node): string
    {
        return match (true) {
            $node instanceof ClassMethod => 'Method '.$node->name->toString().'()',
            $node instanceof Function_ => 'Function '.$node->name->toString().'()',
            $node instanceof Closure, $node instanceof ArrowFunction => 'Closure',
            default => 'Callable',
        };
    }
}
