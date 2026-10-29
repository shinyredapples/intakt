<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Use_;
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
        $imports = $this->importsIn($nodes);

        return [...$this->functionAndConstantImports($imports), ...$this->unneededAliases($imports, $this->declaredClassNames($nodes))];
    }

    /**
     * @param array<Node> $nodes
     *
     * @return list<Import>
     */
    private function importsIn(array $nodes): array
    {
        $imports = [];
        $finder = new NodeFinder();

        foreach ($finder->findInstanceOf($nodes, Use_::class) as $use) {
            foreach ($use->uses as $item) {
                $imports[] = ['name' => $item->name->toString(), 'alias' => $item->alias?->toString(), 'type' => $use->type, 'line' => $item->getStartLine()];
            }
        }

        foreach ($finder->findInstanceOf($nodes, GroupUse::class) as $group) {
            foreach ($group->uses as $item) {
                $imports[] = ['name' => $group->prefix->toString().'\\'.$item->name->toString(), 'alias' => $item->alias?->toString(), 'type' => Use_::TYPE_UNKNOWN === $group->type ? $item->type : $group->type, 'line' => $item->getStartLine()];
            }
        }

        return $imports;
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
     * @return list<string>
     */
    private function declaredClassNames(array $nodes): array
    {
        $classes = new NodeFinder()->findInstanceOf($nodes, ClassLike::class);

        return array_values(array_filter(array_map(static fn (ClassLike $class): ?string => $class->name?->toString(), $classes), is_string(...)));
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
        $shortNameCounts = array_count_values([...array_map(fn (array $import): string => $this->shortName($import['name']), $classes), ...$declaredClassNames]);
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

    private function shortName(string $name): string
    {
        $parts = explode('\\', $name);

        return array_last($parts);
    }
}
