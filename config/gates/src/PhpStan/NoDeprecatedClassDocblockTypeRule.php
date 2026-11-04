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
        // because both virtual nodes carry the attributes of the declaration they wrap, the docblock included, and a trait docblock resolves only inside the class that uses it, where the analyser visits the trait
        return $this->docblocks->reportIn($node->getDocComment(), $scope);
    }
}
