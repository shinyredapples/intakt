<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Comment\Doc;
use PHPStan\Analyser\NameScope;
use PHPStan\Analyser\Scope;
use PHPStan\PhpDoc\TypeNodeResolver;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Type\FileTypeMapper;
use PHPStan\Type\Type;

final readonly class DeprecatedDocblockTypes
{
    public function __construct(
        private FileTypeMapper $typeMapper,
        private TypeNodeResolver $typeResolver,
        private DeprecatedClasses $deprecated,
    ) {
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function reportIn(?Doc $comment, Scope $scope): array
    {
        if (!$comment instanceof Doc) {
            return [];
        }

        // because the analyser files a docblock under the class, trait and function it sits in, the lookup names all three
        $docblock = $this->typeMapper->getResolvedPhpDoc($scope->getFile(), $scope->getClassReflection()?->getName(), $scope->getTraitReflection()?->getName(), $scope->getFunctionName(), $comment->getText());
        $nameScope = $docblock->getNullableNameScope();
        if (!$nameScope instanceof NameScope) {
            return [];
        }

        // because a whole type simplifies before it can be read (object|Legacy becomes object), every name resolves on its own
        $types = array_map(fn (IdentifierTypeNode $name): Type => $this->typeResolver->resolve($name, $nameScope), DocblockTypeNames::in($docblock->getPhpDocNodes()));
        $classes = array_unique(array_merge([], ...array_map(static fn (Type $type): array => $type->getReferencedClasses(), $types)));

        return array_merge([], ...array_map(fn (string $class): array => $this->deprecated->reportUseOf($class, $comment->getStartLine()), $classes));
    }
}
