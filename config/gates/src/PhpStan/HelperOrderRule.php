<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<ClassLike>
 */
final readonly class HelperOrderRule implements Rule
{
    private const array SELF_NAMES = ['self', 'static'];

    // because PHPStan emits no InClassNode for a trait declaration, and analyses a trait's methods only in each class that uses it
    public function getNodeType(): string
    {
        return ClassLike::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // because PHPStan visits a class nested in a trait's method once per class using the trait, at any depth, the outermost declaration checks every class nested in it instead
        if ($scope->isInClass()) {
            return [];
        }

        $classes = [$node, ...new NodeFinder()->findInstanceOf($node->stmts, ClassLike::class)];

        return array_merge(...array_map($this->findingsOf(...), $classes));
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function findingsOf(ClassLike $node): array
    {
        $methods = $node->getMethods();
        $named = array_combine(array_map(static fn (ClassMethod $method): string => $method->name->toLowerString(), $methods), $methods);
        // because the name resolver leaves namespacedName unset on an anonymous class
        $calls = $this->callsOf($methods, $this->helpersOf($methods), isset($node->namespacedName) ? $node->namespacedName->toString() : '');

        return [
            ...$this->helpersAboveTheirFirstCaller($calls, $named),
            ...$this->helpersOutOfCallOrder($calls, $named),
        ];
    }

    /**
     * @param list<ClassMethod> $methods
     *
     * @return array<string, ClassMethod>
     */
    private function helpersOf(array $methods): array
    {
        $helpers = [];

        foreach ($methods as $method) {
            if ($method->isPrivate() && '__construct' !== $method->name->toLowerString()) {
                $helpers[$method->name->toLowerString()] = $method;
            }
        }

        return $helpers;
    }

    // a helper's first caller is the earliest method in the class that calls it, and only that caller's call order places it; a helper no other method of the class leads to (an uncalled one, or one the class reaches only through another object) has no caller to sit below, so it starts its own group
    /**
     * @param list<ClassMethod>          $methods
     * @param array<string, ClassMethod> $helpers
     *
     * @return array<string, list<string>>
     */
    private function callsOf(array $methods, array $helpers, string $class): array
    {
        $called = [];

        foreach ($methods as $method) {
            $called[$method->name->toLowerString()] = $this->calledHelpers($method, $helpers, $class);
        }

        $claimed = array_diff_key($called, $helpers);
        $reachable = $this->reachableFrom($claimed, $called);
        $calls = [];

        foreach ($called as $caller => $helpersCalled) {
            $claimed += isset($reachable[$caller]) ? [] : [$caller => []];
            $firstCalled = array_values(array_filter($helpersCalled, static fn (string $helper): bool => $helper !== $caller && !isset($claimed[$helper])));
            $claimed += array_fill_keys($firstCalled, []);
            $calls[$caller] = $firstCalled;
        }

        return $calls;
    }

    // because an anonymous class has its own $this, a call inside one is not the method's, while a call in a closure or a first-class callable is
    /**
     * @param array<string, ClassMethod> $helpers
     *
     * @return list<string>
     */
    private function calledHelpers(ClassMethod $method, array $helpers, string $class): array
    {
        $finder = new NodeFinder();
        $statements = $method->stmts ?? [];
        $isCall = fn (Node $found): bool => isset($helpers[$this->calledName($found, $class) ?? '']);
        $nested = array_map(spl_object_id(...), $finder->find($finder->findInstanceOf($statements, ClassLike::class), $isCall));
        $calls = array_filter($finder->find($statements, $isCall), static fn (Node $call): bool => !in_array(spl_object_id($call), $nested, true));
        // an expression ends after the expressions nested in it, so ordering by end position puts a call in an argument (or in a closure argument) before the call that encloses it
        usort($calls, static fn (Node $one, Node $other): int => $one->getEndFilePos() <=> $other->getEndFilePos());

        return array_values(array_unique(array_map(fn (Node $call): string => $this->calledName($call, $class) ?? '', $calls)));
    }

    private function calledName(Node $node, string $class): ?string
    {
        if (!$node instanceof MethodCall && !$node instanceof NullsafeMethodCall && !$node instanceof StaticCall) {
            return null;
        }

        $isOwn = $node instanceof StaticCall
            ? $node->class instanceof Name && $this->namesTheClass($node->class, $class)
            : $node->var instanceof Variable && 'this' === $node->var->name;

        return $isOwn && $node->name instanceof Identifier ? $node->name->toLowerString() : null;
    }

    private function namesTheClass(Name $name, string $class): bool
    {
        return in_array($name->toLowerString(), self::SELF_NAMES, true) || strtolower($name->toString()) === strtolower($class);
    }

    /**
     * @param array<string, mixed>        $roots
     * @param array<string, list<string>> $called
     *
     * @return array<string, true>
     */
    private function reachableFrom(array $roots, array $called): array
    {
        $reached = array_fill_keys(array_keys($roots), true);
        $queue = array_keys($roots);

        while ([] !== $queue) {
            foreach ($called[array_shift($queue)] ?? [] as $helper) {
                if (!isset($reached[$helper])) {
                    $reached[$helper] = true;
                    $queue[] = $helper;
                }
            }
        }

        return $reached;
    }

    /**
     * @param array<string, list<string>> $calls
     * @param array<string, ClassMethod>  $named
     *
     * @return list<IdentifierRuleError>
     */
    private function helpersAboveTheirFirstCaller(array $calls, array $named): array
    {
        $findings = [];

        foreach ($calls as $caller => $called) {
            foreach ($called as $helper) {
                if ($named[$helper]->getStartLine() < $named[$caller]->getStartLine()) {
                    $findings[] = $this->finding($named[$helper], sprintf('sits above %s(), its first caller: move it below %1$s()', $named[$caller]->name));
                }
            }
        }

        return $findings;
    }

    /**
     * @param array<string, list<string>> $calls
     * @param array<string, ClassMethod>  $named
     *
     * @return list<IdentifierRuleError>
     */
    private function helpersOutOfCallOrder(array $calls, array $named): array
    {
        $findings = [];

        foreach ($calls as $caller => $called) {
            foreach ($called as $index => $helper) {
                $earlier = array_find(array_slice($called, 0, $index), static fn (string $before): bool => $named[$before]->getStartLine() > $named[$helper]->getStartLine());

                if (null !== $earlier) {
                    $findings[] = $this->finding($named[$helper], sprintf('sits above %s(), which %s() calls first: move it below %1$s()', $named[$earlier]->name, $named[$caller]->name));
                }
            }
        }

        return $findings;
    }

    private function finding(ClassMethod $helper, string $problem): IdentifierRuleError
    {
        $message = sprintf('Private helper %s() %s. Helpers sit below the method that first calls them, in the order it first calls them.', $helper->name, $problem);

        return RuleErrorBuilder::message($message)->identifier('intakt.helperOrder')->line($helper->getStartLine())->build();
    }
}
