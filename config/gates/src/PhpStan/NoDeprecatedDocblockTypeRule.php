<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Trait_;
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
        return DeprecatedDocblockTypes::DOCUMENTED_NODES;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // because a virtual node repeats the docblock of the node it wraps, and a trait body visited through a using class is read once, from the trait's own declaration
        if ($node instanceof VirtualNode || $scope->isInTrait()) {
            return [];
        }

        if ($node instanceof Trait_) {
            return $this->docblocks->reportInTrait($node, $scope->getFile());
        }

        // because a class docblock resolves only inside its class (NoDeprecatedClassDocblockTypeRule)
        if ($node instanceof ClassLike) {
            return [];
        }

        return $this->docblocks->reportIn($node->getDocComment(), DocblockLocation::of($node, $scope));
    }
}
