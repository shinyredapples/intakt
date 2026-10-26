<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<Node\Expr>
 */
final readonly class ClockOnlyRule implements Rule
{
    private const array CLOCK_FUNCTIONS = ['time', 'date', 'microtime'];
    private const string CLOCK_ADAPTER = '/Infrastructure/Clock/';

    public function getNodeType(): string
    {
        return Expr::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (str_contains($scope->getFile(), self::CLOCK_ADAPTER)) {
            return [];
        }

        $problem = $this->problemWith($node);

        if (null === $problem) {
            return [];
        }

        return [RuleErrorBuilder::message($problem.': read the time through Symfony\'s ClockInterface (DateTimeImmutable, UTC).')->identifier('intakt.clockOnly')->build()];
    }

    private function problemWith(Node $node): ?string
    {
        return match (true) {
            $node instanceof FuncCall && $node->name instanceof Name && in_array($node->name->toLowerString(), self::CLOCK_FUNCTIONS, true) => $node->name->toLowerString().'() reads the system time',
            $node instanceof New_ && $this->names($node->class, 'DateTime') => 'DateTime is mutable',
            $node instanceof StaticCall && $this->names($node->class, 'DateTime') => 'DateTime is mutable',
            $node instanceof New_ && $this->names($node->class, 'DateTimeImmutable') && [] === $node->args => 'new DateTimeImmutable() without an argument reads the system time',
            default => null,
        };
    }

    private function names(Node $class, string $name): bool
    {
        return $class instanceof Name && 0 === strcasecmp(ltrim($class->toString(), '\\'), $name);
    }
}
