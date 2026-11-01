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
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<FunctionLike>
 */
final readonly class ParameterLimitRule implements Rule
{
    private const int LIMIT = 3;

    private const array REPOSITORY_CODE = ['/src/', '/tests/', '/bin/', '/config/gates/src/'];

    public function __construct(private string $root)
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
        if ($this->isConstructor($node)) {
            return [];
        }

        $count = count($node->getParams());

        if ($count <= self::LIMIT || $count <= $this->vendorPrototypeParameters($node, $scope)) {
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

    private function vendorPrototypeParameters(FunctionLike $node, Scope $scope): int
    {
        $class = $scope->getClassReflection();

        if (!$node instanceof ClassMethod || null === $class) {
            return 0;
        }

        $name = $node->name->toString();
        // PHP does not let an implementation drop a parameter of the method it implements, so a vendor prototype fixes the count, while one the repository owns can be split
        $counts = array_map(fn (ClassReflection $ancestor): int => $this->vendorParameters($ancestor, $name), [...$class->getInterfaces(), ...$class->getParents()]);

        return max([0, ...$counts]);
    }

    private function vendorParameters(ClassReflection $ancestor, string $name): int
    {
        $native = $ancestor->getNativeReflection();

        if (!$native->hasMethod($name)) {
            return 0;
        }

        $prototype = $native->getMethod($name);
        $file = $prototype->getDeclaringClass()->getFileName();

        return false !== $file && $this->isRepositoryCode($file) ? 0 : $prototype->getNumberOfParameters();
    }

    private function isRepositoryCode(string $file): bool
    {
        return array_any(self::REPOSITORY_CODE, fn (string $folder): bool => str_starts_with($file, $this->root.$folder));
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
