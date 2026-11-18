<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<Class_>
 */
final readonly class FinalClassRule implements Rule
{
    public function getNodeType(): string
    {
        return Class_::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier || $node->isFinal() || $node->isAbstract()) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf('Class %s is not final: declare it final (Doctrine entities too), or abstract if it is a base class.', $node->name->toString()))
                ->identifier('intakt.finalClass')
                ->line($node->getStartLine())
                ->build(),
        ];
    }
}
