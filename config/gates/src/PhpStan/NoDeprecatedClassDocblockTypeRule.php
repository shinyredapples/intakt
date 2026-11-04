<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Node\InTraitNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\MultipleNodeTypesRule;

/**
 * @implements MultipleNodeTypesRule<Node>
 */
final readonly class NoDeprecatedClassDocblockTypeRule implements MultipleNodeTypesRule
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
        return [InClassNode::class, InTraitNode::class];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // because a trait docblock resolves only inside the class that uses it, which is where the analyser visits the trait
        if (!$node instanceof InClassNode && !$node instanceof InTraitNode) {
            return [];
        }

        return $this->docblocks->reportIn($node->getOriginalNode()->getDocComment(), $scope);
    }
}
