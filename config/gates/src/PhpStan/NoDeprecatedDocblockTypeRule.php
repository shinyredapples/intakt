<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PHPStan\Analyser\Scope;
use PHPStan\PhpDoc\ResolvedPhpDocBlock;
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
        $classes = array_unique(array_merge([], ...array_map(static fn (Type $type): array => $type->getReferencedClasses(), $this->types($docblock))));

        return array_merge([], ...array_map(fn (string $class): array => $this->deprecated->reportUseOf($class, $comment->getStartLine()), $classes));
    }

    /**
     * @return list<Type>
     */
    private function types(ResolvedPhpDocBlock $docblock): array
    {
        $tags = [...$docblock->getVarTags(), ...$docblock->getParamTags(), $docblock->getReturnTag(), $docblock->getThrowsTag()];

        return array_values(array_map(static fn (object $tag): Type => $tag->getType(), array_filter($tags, static fn (?object $tag): bool => null !== $tag)));
    }
}
