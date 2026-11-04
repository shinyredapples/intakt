<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PHPStan\PhpDocParser\Ast\AbstractNodeVisitor;
use PHPStan\PhpDocParser\Ast\Node;
use PHPStan\PhpDocParser\Ast\NodeTraverser;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;

final class OutermostTypeNodes extends AbstractNodeVisitor
{
    /** @var list<TypeNode> */
    private array $found = [];

    /**
     * @param array<Node> $phpDocNodes
     *
     * @return list<TypeNode>
     */
    public static function in(array $phpDocNodes): array
    {
        $collector = new self();
        new NodeTraverser([$collector])->traverse($phpDocNodes);

        return $collector->found;
    }

    public function enterNode(Node $node): ?int
    {
        if (!$node instanceof TypeNode) {
            return null;
        }

        // because the outermost type resolves with its context, an inner name like an array shape key is no class
        $this->found[] = $node;

        return NodeTraverser::DONT_TRAVERSE_CHILDREN;
    }
}
