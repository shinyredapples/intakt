<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Param;
use PhpParser\Node\PropertyHook;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\NameScope;
use PHPStan\PhpDoc\TypeNodeResolver;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Type\FileTypeMapper;
use PHPStan\Type\Type;

final readonly class DeprecatedDocblockTypes
{
    /**
     * @var list<class-string<Node>>
     */
    public const array DOCUMENTED_NODES = [Stmt::class, Closure::class, ArrowFunction::class, PropertyHook::class, Param::class];

    public function __construct(
        private FileTypeMapper $typeMapper,
        private TypeNodeResolver $typeResolver,
        private DeprecatedClasses $deprecated,
    ) {
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function reportIn(?Doc $comment, DocblockLocation $location): array
    {
        if (!$comment instanceof Doc) {
            return [];
        }

        $docblock = $this->typeMapper->getResolvedPhpDoc($location->file, $location->class, $location->trait, $location->function, $comment->getText());
        $nameScope = $docblock->getNullableNameScope();
        if (!$nameScope instanceof NameScope) {
            return [];
        }

        // because a whole type simplifies before it can be read (object|Legacy becomes object), every name resolves on its own
        $types = array_map(fn (IdentifierTypeNode $name): Type => $this->typeResolver->resolve($name, $nameScope), DocblockTypeNames::in($docblock->getPhpDocNodes()));
        $classes = array_unique(array_merge([], ...array_map(static fn (Type $type): array => $type->getReferencedClasses(), $types)));

        return array_merge([], ...array_map(fn (string $class): array => $this->deprecated->reportUseOf($class, $comment->getStartLine()), $classes));
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function reportInTrait(Trait_ $trait, string $file): array
    {
        // because the analyser enters a trait body only through a class that uses it, a trait nobody uses is read here from its own declaration
        $reports = [$this->reportIn($trait->getDocComment(), DocblockLocation::inTrait($file, $trait, null))];
        foreach ($trait->stmts as $member) {
            $location = DocblockLocation::inTrait($file, $trait, $member instanceof ClassMethod ? $member->name->toString() : null);
            foreach ($this->documented($member) as $node) {
                $reports[] = $this->reportIn($node->getDocComment(), $location);
            }
        }

        return array_merge(...$reports);
    }

    /**
     * @return array<Node>
     */
    private function documented(Stmt $member): array
    {
        return new NodeFinder()->find([$member], static fn (Node $node): bool => $node->getDocComment() instanceof Doc && array_any(self::DOCUMENTED_NODES, static fn (string $kind): bool => $node instanceof $kind));
    }
}
