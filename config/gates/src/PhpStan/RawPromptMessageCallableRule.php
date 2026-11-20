<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\StaticMethodCallableNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

// PHPStan hands a first-class callable such as `Message::ofUser(...)` to rules as its own node instead of a static call, so this rule passes the call it stands for to RawPromptMessageRule
/**
 * @implements Rule<StaticMethodCallableNode>
 */
final readonly class RawPromptMessageCallableRule implements Rule
{
    private RawPromptMessageRule $messages;

    public function __construct(string $root)
    {
        $this->messages = new RawPromptMessageRule($root);
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
        return $this->messages->processNode($node->getOriginalNode(), $scope);
    }
}
