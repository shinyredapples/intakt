<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<Expr>
 */
final readonly class ClockOnlyRule implements Rule
{
    public const string CLOCK_ADAPTER = 'Intakt\Core\Platform\Infrastructure\Clock\SystemClock';
    private const array CLOCK_FUNCTIONS = [
        'time', 'date', 'microtime', 'date_create', 'date_create_immutable', 'strtotime',
        'mktime', 'gmdate', 'getdate', 'localtime', 'gettimeofday',
    ];
    private const string ABSOLUTE_MOMENT = '/^(@\d+|\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?)?(Z|[+-]\d{2}:?\d{2})?)$/';
    private const string ADVICE = ': read the time through Symfony\'s ClockInterface; parse input with DateTimeImmutable::createFromFormat and an explicit time zone.';

    public function getNodeType(): string
    {
        return Expr::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (0 === strcmp($scope->getClassReflection()?->getName() ?? '', self::CLOCK_ADAPTER)) {
            return [];
        }

        $problem = $this->problemWith($node);

        if (null === $problem) {
            return [];
        }

        return [RuleErrorBuilder::message($problem.self::ADVICE)->identifier('intakt.clockOnly')->build()];
    }

    private function problemWith(Node $node): ?string
    {
        return match (true) {
            $node instanceof FuncCall => $this->functionProblem($node),
            $node instanceof New_ => $this->constructionProblem($node),
            $node instanceof StaticCall => $this->staticCallProblem($node),
            default => null,
        };
    }

    private function functionProblem(FuncCall $call): ?string
    {
        if (!$call->name instanceof Name || !in_array($call->name->toLowerString(), self::CLOCK_FUNCTIONS, true)) {
            return null;
        }

        return $call->name->toLowerString().'() reads or formats the system time';
    }

    private function constructionProblem(New_ $construction): ?string
    {
        return match (true) {
            $this->names($construction->class, 'DateTime') => 'DateTime is mutable',
            !$this->names($construction->class, 'DateTimeImmutable') => null,
            [] === $construction->args => 'new DateTimeImmutable() without an argument reads the system time',
            $this->isAbsoluteMoment($construction->getArgs()[0]->value ?? null) => null,
            default => 'new DateTimeImmutable() with an argument that is not an absolute literal moment can read the system time',
        };
    }

    private function staticCallProblem(StaticCall $call): ?string
    {
        return match (true) {
            $this->names($call->class, 'DateTime') => 'DateTime is mutable',
            !$this->names($call->class, 'DateTimeImmutable') || !$call->name instanceof Identifier => null,
            'createfromformat' === $call->name->toLowerString() && count($call->args) < 3 => 'DateTimeImmutable::createFromFormat() without an explicit time zone uses the system default',
            default => null,
        };
    }

    private function names(Node $class, string $name): bool
    {
        return $class instanceof Name && 0 === strcasecmp(ltrim($class->toString(), '\\'), $name);
    }

    private function isAbsoluteMoment(?Expr $argument): bool
    {
        return $argument instanceof String_ && 1 === preg_match(self::ABSOLUTE_MOMENT, $argument->value);
    }
}
