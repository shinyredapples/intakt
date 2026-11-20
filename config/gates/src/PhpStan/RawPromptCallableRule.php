<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\StaticMethodCallableNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

// PHPStan hands a first-class callable such as Message::ofUser(...) to rules as its own node, never as the call, so RawPromptMessageRule judges the call it stands for
/**
 * @implements Rule<StaticMethodCallableNode>
 */
final readonly class RawPromptCallableRule implements Rule
{
    public function __construct(private RawPromptMessageRule $messages)
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
        return $this->messages->processNode($node->getOriginalNode(), $scope);
    }
}
