<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<FileNode>
 */
final readonly class NoStaticPropertyRule implements Rule
{
    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // because the analyser enters a trait body only through a class that uses it, the file is read whole
        $statics = array_filter(new NodeFinder()->findInstanceOf($node->getNodes(), Property::class), static fn (Property $property): bool => $property->isStatic());

        return array_merge(...array_map($this->findingsOf(...), $statics));
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function findingsOf(Property $declaration): array
    {
        return array_map(
            static fn (PropertyItem $property): IdentifierRuleError => RuleErrorBuilder::message(sprintf('Property $%s is static: no static state, keep it in an instance property or inject a service that holds it.', $property->name->toString()))
                ->identifier('intakt.noStaticProperty')
                ->line($property->getStartLine())
                ->build(),
            array_values($declaration->props),
        );
    }
}
