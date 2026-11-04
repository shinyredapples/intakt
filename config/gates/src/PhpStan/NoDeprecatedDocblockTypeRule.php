<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Param;
use PhpParser\Node\PropertyHook;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PHPStan\Analyser\Scope;
use PHPStan\Node\VirtualNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\MultipleNodeTypesRule;

/**
 * @implements MultipleNodeTypesRule<Node>
 */
final readonly class NoDeprecatedDocblockTypeRule implements MultipleNodeTypesRule
{
    public function __construct(private DeprecatedDocblockTypes $docblocks)
    {
    }

    public function getNodeType(): string
    {
        return Node::class;
    }

    public function getNodeTypes(): array
    {
        return [Stmt::class, Closure::class, ArrowFunction::class, PropertyHook::class, Param::class];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // because a virtual node repeats the docblock of the node it wraps, and a class docblock resolves only inside its class (NoDeprecatedClassDocblockTypeRule)
        if ($node instanceof VirtualNode || $node instanceof ClassLike) {
            return [];
        }

        return $this->docblocks->reportIn($node->getDocComment(), $scope);
    }
}
