<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\StaticMethodCallableNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

// because PHPStan hands `Message::ofUser(...)` to rules as this node, never as the static call RawPromptMessageRule checks, and the callable takes a plain string when it is called
/**
 * @implements Rule<StaticMethodCallableNode>
 */
final readonly class RawPromptCallableRule implements Rule
{
    public function __construct(private string $root)
    {
    }

    public function getNodeType(): string
    {
        return StaticMethodCallableNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        return new RawPromptMessageRule($this->root)->processNode($node->getOriginalNode(), $scope);
    }
}
