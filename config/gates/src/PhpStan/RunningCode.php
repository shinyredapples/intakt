<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use Closure;
use PhpParser\Node;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

final class RunningCode extends NodeVisitorAbstract
{
    private bool $found = false;

    /**
     * @param Closure(Node): bool $match
     */
    private function __construct(
        private readonly Closure $match,
    ) {
    }

    /**
     * @param array<Node>         $nodes
     * @param Closure(Node): bool $match
     */
    public static function anyIn(array $nodes, Closure $match): bool
    {
        $visitor = new self($match);
        new NodeTraverser($visitor)->traverse($nodes);

        return $visitor->found;
    }

    // closures, functions and classes declared here do not run when the code around them does, so what they contain is skipped
    public function enterNode(Node $node): ?int
    {
        if ($node instanceof FunctionLike || $node instanceof ClassLike) {
            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        if (($this->match)($node)) {
            $this->found = true;

            return NodeVisitor::STOP_TRAVERSAL;
        }

        return null;
    }
}
