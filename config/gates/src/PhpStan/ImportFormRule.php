<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UseItem;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @phpstan-type Import array{name: string, alias: ?string, type: int, line: int}
 *
 * @implements Rule<FileNode>
 */
final readonly class ImportFormRule implements Rule
{
    // The one alias AGENTS.md allows, because Doctrine's mapping documentation writes its attributes as ORM\...
    private const array ALLOWED_ALIASES = ['Doctrine\ORM\Mapping' => 'ORM'];

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

        return [
            ...$this->functionAndConstantImports($this->importsIn($nodes)),
            ...array_merge(...array_map($this->unneededAliasesIn(...), $this->namespaceScopes($nodes))),
        ];
    }

    /**
     * @param array<Node> $nodes
     *
     * @return list<Import>
     */
    private function importsIn(array $nodes): array
    {
        $finder = new NodeFinder();

        return [
            ...array_merge(...array_map($this->useImports(...), $finder->findInstanceOf($nodes, Use_::class))),
            ...array_merge(...array_map($this->groupImports(...), $finder->findInstanceOf($nodes, GroupUse::class))),
        ];
    }

    /**
     * @return list<Import>
     */
    private function useImports(Use_ $use): array
    {
        return array_map(
            static fn (UseItem $item): array => ['name' => $item->name->toString(), 'alias' => $item->alias?->toString(), 'type' => $use->type, 'line' => $item->getStartLine()],
            array_values($use->uses),
        );
    }

    /**
     * @return list<Import>
     */
    private function groupImports(GroupUse $group): array
    {
        return array_map(
            static fn (UseItem $item): array => ['name' => $group->prefix->toString().'\\'.$item->name->toString(), 'alias' => $item->alias?->toString(), 'type' => Use_::TYPE_UNKNOWN === $group->type ? $item->type : $group->type, 'line' => $item->getStartLine()],
            array_values($group->uses),
        );
    }

    /**
     * @param list<Import> $imports
     *
     * @return list<IdentifierRuleError>
     */
    private function functionAndConstantImports(array $imports): array
    {
        $functionsAndConstants = array_filter($imports, static fn (array $import): bool => Use_::TYPE_NORMAL !== $import['type']);

        return array_values(array_map(
            static fn (array $import): IdentifierRuleError => RuleErrorBuilder::message("Import of the function or constant {$import['name']}: write global functions and constants plain (count(), PHP_EOL), without a leading backslash or a use function or use const.")
                ->identifier('intakt.functionOrConstantImport')
                ->line($import['line'])
                ->build(),
            $functionsAndConstants,
        ));
    }

    /**
     * @param array<Node> $nodes
     *
     * @return list<IdentifierRuleError>
     */
    private function unneededAliasesIn(array $nodes): array
    {
        return $this->unneededAliases($this->importsIn($nodes), $this->declaredClassNames($nodes));
    }

    /**
     * @param array<Node> $nodes
     *
     * @return list<array<Node>>
     */
    private function namespaceScopes(array $nodes): array
    {
        $namespaces = array_values(array_filter($nodes, static fn (Node $node): bool => $node instanceof Namespace_));

        return [] === $namespaces ? [$nodes] : array_map(static fn (Namespace_ $namespace): array => $namespace->stmts, $namespaces);
    }

    /**
     * @param array<Node> $nodes
     *
     * @return list<string>
     */
    private function declaredClassNames(array $nodes): array
    {
        $classes = new NodeFinder()->findInstanceOf($nodes, ClassLike::class);

        // isset because the name resolver leaves namespacedName uninitialized on an anonymous class inside a closure, where ?-> throws instead of giving null
        return array_values(array_filter(array_map(static fn (ClassLike $class): ?string => isset($class->namespacedName) ? $class->namespacedName->toLowerString() : null, $classes), is_string(...)));
    }

    /**
     * @param list<Import> $imports
     * @param list<string> $declaredClassNames
     *
     * @return list<IdentifierRuleError>
     */
    private function unneededAliases(array $imports, array $declaredClassNames): array
    {
        $classes = array_values(array_filter($imports, static fn (array $import): bool => Use_::TYPE_NORMAL === $import['type']));
        // Distinct class names only, so importing the same class twice is no clash that needs an alias.
        $classNames = array_unique([...array_map(static fn (array $import): string => strtolower($import['name']), $classes), ...$declaredClassNames]);
        $shortNameCounts = array_count_values(array_map($this->shortName(...), $classNames));
        $unneeded = array_filter(
            $classes,
            fn (array $import): bool => null !== $import['alias']
                && (self::ALLOWED_ALIASES[$import['name']] ?? null) !== $import['alias']
                && 1 === $shortNameCounts[$this->shortName($import['name'])],
        );

        return array_values(array_map(
            static fn (array $import): IdentifierRuleError => RuleErrorBuilder::message("Unneeded import alias {$import['alias']} for {$import['name']}: an alias is allowed only where two imported classes share a short name, and for Doctrine's ORM.")
                ->identifier('intakt.unneededImportAlias')
                ->line($import['line'])
                ->build(),
            $unneeded,
        ));
    }

    // Lowercase because PHP class names are case-insensitive, so Client and CLIENT clash.
    private function shortName(string $name): string
    {
        $parts = explode('\\', strtolower($name));

        return array_last($parts);
    }
}
