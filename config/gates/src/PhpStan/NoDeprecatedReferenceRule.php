<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UseItem;
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
        $references = [...$this->imports($nodes), ...$this->classNames($nodes)];
        $deprecated = array_filter(array_unique(array_column($references, 'name')), $this->deprecated->isDeprecated(...));
        $uses = array_filter($references, static fn (array $reference): bool => in_array($reference['name'], $deprecated, true));

        return array_merge([], ...array_map(fn (array $reference): array => $this->deprecated->reportUseOf($reference['name'], $reference['line']), array_values($uses)));
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
            static fn (Use_ $use): array => array_map(static fn (UseItem $item): array => ['name' => $item->name->toString(), 'line' => $item->getStartLine()], $use->uses),
            array_filter($finder->findInstanceOf($nodes, Use_::class), static fn (Use_ $use): bool => Use_::TYPE_NORMAL === $use->type),
        );
        $grouped = array_map(
            static fn (GroupUse $group): array => array_map(static fn (UseItem $item): array => ['name' => $group->prefix->toString().'\\'.$item->name->toString(), 'line' => $item->getStartLine()], $group->uses),
            array_filter($finder->findInstanceOf($nodes, GroupUse::class), static fn (GroupUse $group): bool => Use_::TYPE_NORMAL === $group->type),
        );

        return array_values(array_merge([], ...$plain, ...$grouped));
    }

    // because PHPStan has resolved every class name to its full form before rules run, so any other name is a class reference
    /**
     * @param array<Node> $nodes
     *
     * @return list<Reference>
     */
    private function classNames(array $nodes): array
    {
        $finder = new NodeFinder();
        $others = array_map(spl_object_id(...), $this->otherNames($finder, $nodes));
        $names = array_filter($finder->findInstanceOf($nodes, Name::class), static fn (Name $name): bool => !in_array(spl_object_id($name), $others, true));

        return array_values(array_map(static fn (Name $name): array => ['name' => $name->toString(), 'line' => $name->getStartLine()], $names));
    }

    /**
     * @param array<Node> $nodes
     *
     * @return list<Node>
     */
    private function otherNames(NodeFinder $finder, array $nodes): array
    {
        $functions = array_map(static fn (FuncCall $call): Node => $call->name, $finder->findInstanceOf($nodes, FuncCall::class));
        $constants = array_map(static fn (ConstFetch $fetch): Node => $fetch->name, $finder->findInstanceOf($nodes, ConstFetch::class));
        $namespaces = array_map(static fn (Namespace_ $namespace): ?Node => $namespace->name, $finder->findInstanceOf($nodes, Namespace_::class));
        $imported = array_map(static fn (UseItem $item): Node => $item->name, $finder->findInstanceOf($nodes, UseItem::class));
        $prefixes = array_map(static fn (GroupUse $group): Node => $group->prefix, $finder->findInstanceOf($nodes, GroupUse::class));

        return array_values(array_filter([...$functions, ...$constants, ...$namespaces, ...$imported, ...$prefixes]));
    }
}
