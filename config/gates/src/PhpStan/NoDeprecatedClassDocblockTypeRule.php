<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<InClassNode>
 */
final readonly class NoDeprecatedClassDocblockTypeRule implements Rule
{
    public function __construct(private DeprecatedDocblockTypes $docblocks)
    {
    }

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // because the virtual node carries the attributes of the class declaration it wraps, the docblock included, and a class docblock resolves only inside its class
        return $this->docblocks->reportIn($node->getDocComment(), DocblockLocation::of($node, $scope));
    }
}
