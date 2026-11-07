<?php

declare(strict_types=1);

namespace Intakt\Gates;

// One fragment per change, so two pull requests never edit the same lines of docs/highlights.md.
final readonly class HighlightFragments
{
    public const string ASSEMBLED = 'docs/highlights.md';

    private const string DIRECTORY = 'docs/highlights.d';

    private const string LAYOUT = self::DIRECTORY.'/layout.md';

    private const string FRAGMENT_NAME = '/^\d+-[a-z0-9]+(?:-[a-z0-9]+)*\.md$/';

    private const string SECTION_HEADING = '/^## (.+)$/';

    private const string SEPARATOR_ROW = '/^\|?(?:\s*:?-+:?\s*\|)*\s*:?-+:?\s*\|?$/';

    private const string CELL_BORDER = '/(?<!\\\\)\|/';

    /**
     * @return list<string>
     */
    public function findingsAgainst(string $root, ?string $assembled): array
    {
        if (!is_dir($root.'/'.self::DIRECTORY)) {
            return [];
        }

        $assembly = $this->assembled($root);

        if ([] !== $assembly->findings) {
            return $assembly->findings;
        }

        if ($assembly->text === $assembled) {
            return [];
        }

        return [self::ASSEMBLED.' is not the assembly of '.self::DIRECTORY.'/ (edited by hand or not rebuilt): add or change a fragment there and run bin/highlights'];
    }

    public function assembled(string $root): HighlightAssembly
    {
        $layout = $this->linesOf($root.'/'.self::LAYOUT);

        if (null === $layout) {
            return new HighlightAssembly('', ['cannot read '.self::LAYOUT]);
        }

        [$preamble, $sections] = $this->sectionsOf($layout);
        $names = $this->fragmentNames($root);
        $findings = $this->nameFindings($names);
        $rows = array_fill_keys(array_keys($sections), []);

        foreach ($this->fragmentsInOrder($names) as $name) {
            [$fragmentRows, $fragmentFindings] = $this->rowsOf($name, $this->linesOf($root.'/'.self::DIRECTORY.'/'.$name), $sections);
            $findings = [...$findings, ...$fragmentFindings];
            $rows = $this->withReplacedRows($rows, $fragmentRows);
        }

        return new HighlightAssembly([] === $findings ? $this->textOf($preamble, $sections, $rows) : '', $findings);
    }

    /**
     * @param list<string> $layout
     *
     * @return array{list<string>, array<string, array{lines: list<string>, kind: HighlightRowKind}>}
     */
    private function sectionsOf(array $layout): array
    {
        $preamble = [];
        $bodies = [];
        $current = null;

        foreach ($layout as $line) {
            if (1 === preg_match(self::SECTION_HEADING, $line, $heading)) {
                $current = $heading[1];
                $bodies[$current] = [];

                continue;
            }

            if (null === $current) {
                $preamble[] = $line;

                continue;
            }

            $bodies[$current][] = $line;
        }

        return [$preamble, array_map($this->sectionOf(...), $bodies)];
    }

    /**
     * @param list<string> $body
     *
     * @return array{lines: list<string>, kind: HighlightRowKind}
     */
    private function sectionOf(array $body): array
    {
        $lines = $this->withoutTrailingBlanks($body);
        $isTable = 1 === preg_match(self::SEPARATOR_ROW, array_last($lines) ?? '');

        return ['lines' => $lines, 'kind' => $isTable ? HighlightRowKind::TableRow : HighlightRowKind::ListItem];
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

        return array_values(array_diff($entries, ['.', '..', basename(self::LAYOUT)]));
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
     * @param list<string>|null                                                $lines
     * @param array<string, array{lines: list<string>, kind: HighlightRowKind}> $sections
     *
     * @return array{array<string, array<string, string>>, list<string>}
     */
    private function rowsOf(string $name, ?array $lines, array $sections): array
    {
        $file = self::DIRECTORY.'/'.$name;

        if (null === $lines) {
            return [[], ['cannot read '.$file]];
        }

        $rows = [];
        $findings = [];
        $inSection = false;
        $section = null;

        foreach ($lines as $index => $line) {
            $at = $file.':'.($index + 1).': ';

            if ('' === trim($line)) {
                continue;
            }

            if (1 === preg_match(self::SECTION_HEADING, $line, $heading)) {
                $inSection = true;
                $section = isset($sections[$heading[1]]) ? $heading[1] : null;

                if (null === $section) {
                    $findings[] = $at.'section "'.$heading[1].'" is not a section of '.self::LAYOUT;
                }

                continue;
            }

            if (!$inSection) {
                $findings[] = $at.'the line is outside a section, start the fragment with a "## <section>" heading of '.self::LAYOUT;

                continue;
            }

            // The heading was reported already, so its rows add nothing.
            if (null === $section) {
                continue;
            }

            $finding = $this->rowFinding($line, $section, $sections[$section]['kind'], $rows[$section] ?? []);

            if (null !== $finding) {
                $findings[] = $at.$finding;

                continue;
            }

            $rows[$section][$this->keyOf($line, $sections[$section]['kind'])] = $line;
        }

        return [$rows, $findings];
    }

    /**
     * @param array<string, string> $sectionRows
     */
    private function rowFinding(string $line, string $section, HighlightRowKind $kind, array $sectionRows): ?string
    {
        if (!str_starts_with($line, $kind->value)) {
            return 'section "'.$section.'" holds '.$kind->noun().', each starting with "'.$kind->value.'"';
        }

        if (HighlightRowKind::TableRow === $kind && 1 === preg_match(self::SEPARATOR_ROW, $line)) {
            return 'a fragment holds rows only, the table header is in '.self::LAYOUT;
        }

        $key = $this->keyOf($line, $kind);

        return isset($sectionRows[$key]) ? 'row "'.$key.'" appears twice in this fragment' : null;
    }

    private function keyOf(string $line, HighlightRowKind $kind): string
    {
        if (HighlightRowKind::ListItem === $kind) {
            return $line;
        }

        $cells = preg_split(self::CELL_BORDER, substr($line, 1));

        return trim(false === $cells ? $line : $cells[0]);
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
     * @param list<string>                                                     $preamble
     * @param array<string, array{lines: list<string>, kind: HighlightRowKind}> $sections
     * @param array<string, array<string, string>>                             $rows
     */
    private function textOf(array $preamble, array $sections, array $rows): string
    {
        $lines = [...$this->withoutTrailingBlanks($preamble), ''];

        foreach ($sections as $name => $section) {
            $sectionRows = array_values($rows[$name] ?? []);
            $lines = [...$lines, '## '.$name, ...$section['lines']];

            // A list needs a blank line after its heading, while table rows continue the header.
            if (HighlightRowKind::ListItem === $section['kind'] && [] !== $sectionRows) {
                $lines[] = '';
            }

            $lines = [...$lines, ...$sectionRows, ''];
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function withoutTrailingBlanks(array $lines): array
    {
        while ([] !== $lines && '' === trim((string) array_last($lines))) {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * @return list<string>|null
     */
    private function linesOf(string $file): ?array
    {
        $contents = is_file($file) ? file_get_contents($file) : false;

        return false === $contents ? null : array_map(rtrim(...), explode("\n", $contents));
    }
}
