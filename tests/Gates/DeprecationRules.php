<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\NoDeprecatedClassDocblockTypeRule;
use Intakt\Gates\PhpStan\NoDeprecatedDocblockTypeRule;
use Intakt\Gates\PhpStan\NoDeprecatedReferenceRule;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

// because PHPStan's CompositeRule takes rules of one node type only, and these three report on different nodes
/**
 * @implements Rule<Node>
 */
final readonly class DeprecationRules implements Rule
{
    public function __construct(
        private NoDeprecatedReferenceRule $references,
        private NoDeprecatedDocblockTypeRule $docblocks,
        private NoDeprecatedClassDocblockTypeRule $classDocblocks,
    ) {
    }

    public function getNodeType(): string
    {
        return Node::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];

        if ($node instanceof ($this->references->getNodeType())) {
            $errors = [...$errors, ...$this->references->processNode($node, $scope)];
        }

        if (array_any($this->docblocks->getNodeTypes(), static fn (string $type): bool => $node instanceof $type)) {
            $errors = [...$errors, ...$this->docblocks->processNode($node, $scope)];
        }

        if ($node instanceof ($this->classDocblocks->getNodeType())) {
            return [...$errors, ...$this->classDocblocks->processNode($node, $scope)];
        }

        return $errors;
    }
}
