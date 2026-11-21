<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\Trait_;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;

/**
 * @implements Collector<Trait_, array{string, non-empty-list<int>}>
 */
final readonly class RunTimeTraitConstructionCollector implements Collector
{
    private RolePromptCarriers $carriers;

    public function __construct()
    {
        $this->carriers = new RolePromptCarriers(new ClassNames());
    }

    public function getNodeType(): string
    {
        return Trait_::class;
    }

    /**
     * @return array{string, non-empty-list<int>}|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $lines = $this->carriers->runTimeConstructionLines($node);

        return [] === $lines || null === $node->namespacedName ? null : [$node->namespacedName->toString(), $lines];
    }
}
