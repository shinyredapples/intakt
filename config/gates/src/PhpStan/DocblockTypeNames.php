<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PHPStan\PhpDocParser\Ast\AbstractNodeVisitor;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Node;
use PHPStan\PhpDocParser\Ast\NodeTraverser;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeItemNode;

final class DocblockTypeNames extends AbstractNodeVisitor
{
    /** @var list<IdentifierTypeNode> */
    private array $found = [];

    /** @var list<int> */
    private array $shapeKeys = [];

    /**
     * @param array<Node> $phpDocNodes
     *
     * @return list<IdentifierTypeNode>
     */
    public static function in(array $phpDocNodes): array
    {
        $collector = new self();
        new NodeTraverser([$collector])->traverse($phpDocNodes);

        return $collector->found;
    }

    public function enterNode(Node $node): ?int
    {
        // because a shape key reads like a type name but names a key, never a class
        if (($node instanceof ArrayShapeItemNode || $node instanceof ObjectShapeItemNode) && $node->keyName instanceof IdentifierTypeNode) {
            $this->shapeKeys[] = spl_object_id($node->keyName);
        }
        if ($node instanceof ConstFetchNode && '' !== $node->className) {
            $this->found[] = new IdentifierTypeNode($node->className);
        }
        if ($node instanceof IdentifierTypeNode && !in_array(spl_object_id($node), $this->shapeKeys, true)) {
            $this->found[] = $node;
        }

        return null;
    }
}
