<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;

/**
 * @implements Collector<InClassNode, non-empty-list<string>>
 */
final readonly class UsedTraitCollector implements Collector
{
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    // because a trait a trait uses is used by the class too, the traits are read recursively
    /**
     * @return non-empty-list<string>|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $traits = array_values(array_map(static fn (ClassReflection $trait): string => $trait->getName(), $node->getClassReflection()->getTraits(true)));

        return [] === $traits ? null : $traits;
    }
}
