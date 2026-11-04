<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * @phpstan-type Reference array{name: string, line: int}
 *
 * @implements Rule<FileNode>
 */
final readonly class NoDeprecatedReferenceRule implements Rule
{
    public function __construct(private DeprecatedClasses $deprecated)
    {
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $nodes = $node->getNodes();
        $references = [...$this->imports($nodes), ...$this->namedClasses($nodes)];

        return array_merge([], ...array_map(fn (array $reference): array => $this->deprecated->reportUseOf($reference['name'], $reference['line']), $references));
    }

    /**
     * @param array<Node> $nodes
     *
     * @return list<Reference>
     */
    private function imports(array $nodes): array
    {
        $finder = new NodeFinder();
        $plain = array_map(
            static fn (Use_ $use): array => array_map(static fn ($item): array => ['name' => $item->name->toString(), 'line' => $item->getStartLine()], $use->uses),
            array_filter($finder->findInstanceOf($nodes, Use_::class), static fn (Use_ $use): bool => Use_::TYPE_NORMAL === $use->type),
        );
        $grouped = array_map(
            static fn (GroupUse $group): array => array_map(static fn ($item): array => ['name' => $group->prefix->toString().'\\'.$item->name->toString(), 'line' => $item->getStartLine()], $group->uses),
            array_filter($finder->findInstanceOf($nodes, GroupUse::class), static fn (GroupUse $group): bool => Use_::TYPE_NORMAL === $group->type),
        );

        return array_values(array_merge([], ...$plain, ...$grouped));
    }

    /**
     * The names are already resolved to their full form by the time rules run.
     *
     * @param array<Node> $nodes
     *
     * @return list<Reference>
     */
    private function namedClasses(array $nodes): array
    {
        $finder = new NodeFinder();
        $instanceOfs = array_map(static fn (Instanceof_ $node): array => [$node->class], $finder->findInstanceOf($nodes, Instanceof_::class));
        $catches = array_map(static fn (Catch_ $node): array => $node->types, $finder->findInstanceOf($nodes, Catch_::class));
        $attributes = array_map(static fn (Attribute $node): array => [$node->name], $finder->findInstanceOf($nodes, Attribute::class));
        $classFetches = array_map(static fn (ClassConstFetch $node): array => [$node->class], array_filter($finder->findInstanceOf($nodes, ClassConstFetch::class), $this->fetchesClassName(...)));

        $names = array_filter(array_merge([], ...$instanceOfs, ...$catches, ...$attributes, ...$classFetches), static fn (Node $name): bool => $name instanceof Name);

        return array_values(array_map(static fn (Name $name): array => ['name' => $name->toString(), 'line' => $name->getStartLine()], $names));
    }

    private function fetchesClassName(ClassConstFetch $fetch): bool
    {
        return $fetch->name instanceof Identifier && 'class' === $fetch->name->toLowerString();
    }
}
