<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class HighlightFragment
{
    private const string CELL_BORDER = '/(?<!\\\\)\|/';

    public function __construct(
        private string $file,
        private HighlightLayout $layout,
    ) {
    }

    /**
     * @param list<string> $lines
     *
     * @return array{array<string, array<string, string>>, list<string>}
     */
    public function rowsOf(array $lines): array
    {
        $rows = [];
        $findings = [];

        foreach ($this->sectionsByLine($lines) as $index => $section) {
            $line = $lines[$index];
            $finding = $this->findingOf($line, $section, $rows);

            if (null !== $finding) {
                $findings[] = $this->file.':'.($index + 1).': '.$finding;

                continue;
            }

            $kind = null === $section ? null : $this->layout->kindOf($section);

            if (null !== $section && $kind instanceof HighlightRowKind && str_starts_with($line, $kind->value)) {
                $rows[$section][$this->keyOf($line, $kind)] = $line;
            }
        }

        return [$rows, $findings];
    }

    /**
     * @param list<string> $lines
     *
     * @return array<int, string|null>
     */
    private function sectionsByLine(array $lines): array
    {
        $sections = [];
        $section = null;

        foreach ($lines as $index => $line) {
            $section = 1 === preg_match(HighlightLayout::SECTION_HEADING, $line, $heading) ? $heading[1] : $section;
            $sections[$index] = $section;
        }

        return $sections;
    }

    /**
     * @param array<string, array<string, string>> $rows
     */
    private function findingOf(string $line, ?string $section, array $rows): ?string
    {
        if ('' === trim($line)) {
            return null;
        }

        if (1 === preg_match(HighlightLayout::SECTION_HEADING, $line, $heading)) {
            return $this->layout->kindOf($heading[1]) instanceof HighlightRowKind ? null : 'section "'.$heading[1].'" is not a section of '.HighlightLayout::FILE;
        }

        if (null === $section) {
            return 'the line is outside a section, start the fragment with a "## <section>" heading of '.HighlightLayout::FILE;
        }

        // An unknown heading was reported already, so its rows add nothing.
        $kind = $this->layout->kindOf($section);

        if (!$kind instanceof HighlightRowKind) {
            return null;
        }

        if (!str_starts_with($line, $kind->value)) {
            return 'section "'.$section.'" holds '.$kind->noun().', each starting with "'.$kind->value.'"';
        }

        return $this->rowFinding($line, $kind, $rows[$section] ?? []);
    }

    /**
     * @param array<string, string> $sectionRows
     */
    private function rowFinding(string $line, HighlightRowKind $kind, array $sectionRows): ?string
    {
        if (HighlightRowKind::TableRow === $kind && 1 === preg_match(HighlightLayout::SEPARATOR_ROW, $line)) {
            return 'a fragment holds rows only, the table header is in '.HighlightLayout::FILE;
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
}
