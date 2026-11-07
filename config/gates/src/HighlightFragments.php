<?php

declare(strict_types=1);

namespace Intakt\Gates;

// One fragment per change, so two pull requests never edit the same lines of docs/highlights.md.
final readonly class HighlightFragments
{
    public const string ASSEMBLED = 'docs/highlights.md';

    private const string DIRECTORY = 'docs/highlights.d';

    private const string FRAGMENT_NAME = '/^\d+-[a-z0-9]+(?:-[a-z0-9]+)*\.md$/';

    /**
     * @return list<string>
     */
    public function findingsOn(string $root): array
    {
        if (!is_dir($root.'/'.self::DIRECTORY)) {
            return [];
        }

        $assembly = $this->assembled($root);

        if ([] !== $assembly->findings) {
            return $assembly->findings;
        }

        if ($assembly->text === $this->contentsOf($root.'/'.self::ASSEMBLED)) {
            return [];
        }

        return [self::ASSEMBLED.' is not the assembly of '.self::DIRECTORY.'/ (edited by hand or not rebuilt): add or change a fragment there and run bin/highlights'];
    }

    public function assembled(string $root): HighlightAssembly
    {
        $layout = $this->linesOf($root.'/'.HighlightLayout::FILE);

        if (null === $layout) {
            return new HighlightAssembly('', ['cannot read '.HighlightLayout::FILE]);
        }

        $layout = HighlightLayout::fromLines($layout);
        $names = $this->fragmentNames($root);
        $findings = $this->nameFindings($names);
        $rows = [];

        foreach ($this->fragmentsInOrder($names) as $name) {
            [$fragmentRows, $fragmentFindings] = $this->rowsOf($root, $name, $layout);
            $findings = [...$findings, ...$fragmentFindings];
            $rows = $this->withReplacedRows($rows, $fragmentRows);
        }

        return new HighlightAssembly([] === $findings ? $layout->assembledWith($rows) : '', $findings);
    }

    /**
     * @return list<string>
     */
    private function fragmentNames(string $root): array
    {
        $entries = scandir($root.'/'.self::DIRECTORY);

        if (false === $entries) {
            return [];
        }

        return array_values(array_diff($entries, ['.', '..', basename(HighlightLayout::FILE)]));
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function nameFindings(array $names): array
    {
        $misnamed = array_filter($names, static fn (string $name): bool => 1 !== preg_match(self::FRAGMENT_NAME, $name));

        return array_values(array_map(
            static fn (string $name): string => self::DIRECTORY.'/'.$name.': a fragment is named <issue>-<slug>.md, the slug in lowercase letters, digits and hyphens',
            $misnamed,
        ));
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function fragmentsInOrder(array $names): array
    {
        $fragments = array_values(array_filter($names, static fn (string $name): bool => 1 === preg_match(self::FRAGMENT_NAME, $name)));

        // Natural order, so fragment 10 applies after fragment 2 and replaces a row it shares with it.
        usort($fragments, strnatcmp(...));

        return $fragments;
    }

    /**
     * @return array{array<string, array<string, string>>, list<string>}
     */
    private function rowsOf(string $root, string $name, HighlightLayout $layout): array
    {
        $file = self::DIRECTORY.'/'.$name;
        $lines = $this->linesOf($root.'/'.$file);

        return null === $lines ? [[], ['cannot read '.$file]] : new HighlightFragment($file, $layout)->rowsOf($lines);
    }

    /**
     * @param array<string, array<string, string>> $rows
     * @param array<string, array<string, string>> $replacements
     *
     * @return array<string, array<string, string>>
     */
    private function withReplacedRows(array $rows, array $replacements): array
    {
        foreach ($replacements as $section => $sectionRows) {
            foreach ($sectionRows as $key => $row) {
                $rows[$section][$key] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return list<string>|null
     */
    private function linesOf(string $file): ?array
    {
        $contents = $this->contentsOf($file);

        return null === $contents ? null : array_map(rtrim(...), explode("\n", $contents));
    }

    private function contentsOf(string $file): ?string
    {
        $contents = is_file($file) ? file_get_contents($file) : false;

        return false === $contents ? null : $contents;
    }
}
