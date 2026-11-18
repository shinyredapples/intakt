<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use PHPStan\Parser\Parser;
use PHPStan\Parser\ParserErrorsException;
use PHPStan\Reflection\ReflectionProvider;

final readonly class FileScopeDeprecation
{
    private const string FUNCTION = 'trigger_deprecation';

    public function __construct(
        private Parser $parser,
        private ReflectionProvider $reflections,
    ) {
    }

    public function triggeredBy(?string $file): bool
    {
        if (null === $file || !is_file($file)) {
            return false;
        }

        try {
            $statements = $this->parser->parseFile($file);
        } catch (ParserErrorsException) {
            return false;
        }

        return [] !== array_diff($this->triggersIn($statements), $this->triggersIn($this->bodies($statements)));
    }

    /**
     * @param array<Node> $nodes
     *
     * @return array<int>
     */
    private function triggersIn(array $nodes): array
    {
        return array_map(spl_object_id(...), new NodeFinder()->find($nodes, $this->isTrigger(...)));
    }

    /**
     * @param array<Node> $statements
     *
     * @return array<Node>
     */
    private function bodies(array $statements): array
    {
        // a call inside a function, closure or class runs only when someone calls it, so it deprecates nothing at load time
        return new NodeFinder()->find($statements, static fn (Node $node): bool => $node instanceof FunctionLike || $node instanceof ClassLike);
    }

    private function isTrigger(Node $node): bool
    {
        // because PHP resolves a function name in any case, and a first-class callable (trigger_deprecation(...)) is a FuncCall that warns nobody
        return $node instanceof FuncCall
            && !$node->isFirstClassCallable()
            && $node->name instanceof Name
            && self::FUNCTION === strtolower($this->calledFunction($node->name));
    }

    private function calledFunction(Name $name): string
    {
        // because the parser resolves a qualified or imported name, while an unqualified one falls back to the global function only when the namespace declares none of that name
        $resolved = $name->getAttribute('resolvedName');
        if ($resolved instanceof Name) {
            return $resolved->toString();
        }

        $namespaced = $name->getAttribute('namespacedName');
        if ($namespaced instanceof Name && $this->reflections->hasFunction($namespaced, null)) {
            return $namespaced->toString();
        }

        return $name->toString();
    }
}
