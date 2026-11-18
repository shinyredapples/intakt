<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<Property>
 */
final readonly class NoStaticPropertyRule implements Rule
{
    public function getNodeType(): string
    {
        return Property::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->isStatic()) {
            return [];
        }

        $errors = [];

        foreach ($node->props as $property) {
            $errors[] = RuleErrorBuilder::message(sprintf('Property $%s is static: no static state, keep it in an instance property or inject a service that holds it.', $property->name->toString()))
                ->identifier('intakt.noStaticProperty')
                ->line($property->getStartLine())
                ->build();
        }

        return $errors;
    }
}
