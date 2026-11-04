<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PHPStan\Analyser\NameScope;
use PHPStan\Analyser\Scope;
use PHPStan\PhpDoc\TypeNodeResolver;
use PHPStan\PhpDocParser\Ast\AbstractNodeVisitor;
use PHPStan\PhpDocParser\Ast\Node as PhpDocNode;
use PHPStan\PhpDocParser\Ast\NodeTraverser;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Type\FileTypeMapper;
use PHPStan\Type\Type;

/**
 * @implements Rule<Stmt>
 */
final readonly class NoDeprecatedDocblockTypeRule implements Rule
{
    public function __construct(
        private FileTypeMapper $typeMapper,
        private TypeNodeResolver $typeResolver,
        private DeprecatedClasses $deprecated,
    ) {
    }

    public function getNodeType(): string
    {
        return Stmt::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $comment = $node->getDocComment();
        if (!$comment instanceof Doc) {
            return [];
        }

        $docblock = $this->typeMapper->getResolvedPhpDoc($scope->getFile(), $scope->getClassReflection()?->getName(), null, null, $comment->getText());
        $nameScope = $docblock->getNullableNameScope();
        if (!$nameScope instanceof NameScope) {
            return [];
        }

        $types = array_map(fn (TypeNode $type): Type => $this->typeResolver->resolve($type, $nameScope), $this->typeNodes($docblock->getPhpDocNodes()));
        $classes = array_unique(array_merge([], ...array_map(static fn (Type $type): array => $type->getReferencedClasses(), $types)));

        return array_merge([], ...array_map(fn (string $class): array => $this->deprecated->reportUseOf($class, $comment->getStartLine()), $classes));
    }

    // because every tag keeps its types in other fields (@method parameters, @template bounds), only a walk finds them all
    /**
     * @param array<PhpDocNode> $phpDocNodes
     *
     * @return list<TypeNode>
     */
    private function typeNodes(array $phpDocNodes): array
    {
        $collector = new class extends AbstractNodeVisitor {
            /** @var list<TypeNode> */
            public array $found = [];

            public function enterNode(PhpDocNode $node): ?int
            {
                if (!$node instanceof TypeNode) {
                    return null;
                }

                $this->found[] = $node;

                return NodeTraverser::DONT_TRAVERSE_CHILDREN;
            }
        };
        new NodeTraverser([$collector])->traverse($phpDocNodes);

        return $collector->found;
    }
}
