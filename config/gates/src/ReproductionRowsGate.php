<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class ReproductionRowsGate implements Gate
{
    private const string HIGHLIGHTS = 'docs/highlights.md';

    private const string SECTION = '## Found in the beta';

    private const string NEXT_SECTION = '/^#{1,2} /';

    private const string REPRODUCTION_FILE = '#^upstream/([^/]+)/#';

    // A path starts at `upstream/` and ends where a code span ends, so `docs/upstream/a-bug/` and `upstream/a-bug-two/` name no `a-bug`.
    private const string NAMED_FOLDER = '#(?<![\w/.-])upstream/([^/\s`|<>]+)#';

    private const string DELIMITER_ROW = '/^[\s|:-]*-[\s|:-]*$/';

    private const string CELL_BORDER = '/(?<!\\\\)\|/';

    private const array OUTER_BORDERS = ['/^\s*\|/', '/(?<!\\\\)\|\s*$/'];

    private const string BLOCK_START = '/^ {0,3}(?:[#><]|[-*+][ \t]|\d+[.)][ \t])/';

    private const string INDENTED_CODE = '/^(?: {4}| {0,3}\t)/';

    public function __construct(
        private FencedCodeBlocks $codeBlocks,
        private CheckedReports $reports,
        private HighlightFragments $highlights,
    ) {
    }

    public function name(): string
    {
        return 'reproductions';
    }

    public function run(Workspace $workspace): GateResult
    {
        try {
            $folders = $this->reproductionFolders($workspace);
        } catch (ScopeFailure $failure) {
            return GateResult::failedBecause($this->name(), $failure->getMessage());
        }

        $page = $this->highlights->pageOn($workspace->root);

        if ($page instanceof HighlightAssembly && [] !== $page->findings) {
            return GateResult::failedBecause($this->name(), 'cannot read '.self::HIGHLIGHTS.': '.$page->findings[0]);
        }

        $contents = $page->text ?? '';
        $findings = array_map(
            static fn (string $folder): string => 'upstream/'.$folder.'/ has no row in "Found in the beta" of '.self::HIGHLIGHTS,
            array_values(array_diff($folders, $this->foldersWithARow($contents))),
        );

        $findings = [...$findings, ...$this->reports->findings($workspace, $folders)];

        return [] === $findings ? GateResult::passed($this->name()) : GateResult::withFindings($this->name(), $findings);
    }

    /**
     * @return list<string>
     */
    private function reproductionFolders(Workspace $workspace): array
    {
        $folders = [];

        foreach ($workspace->repositoryFiles() as $path) {
            if (1 === preg_match(self::REPRODUCTION_FILE, $path, $match)) {
                $folders[$match[1]] = true;
            }
        }

        $names = array_keys($folders);
        sort($names);

        return array_map(strval(...), $names);
    }

    /**
     * @return list<string>
     */
    private function foldersWithARow(string $contents): array
    {
        $named = [];

        foreach ($this->sectionRows($contents) as $row) {
            preg_match_all(self::NAMED_FOLDER, $row, $matches);
            $named = [...$named, ...$matches[1]];
        }

        return $named;
    }

    /**
     * @return list<string>
     */
    private function sectionRows(string $contents): array
    {
        $section = [];
        $inSection = false;

        foreach ($this->codeBlocks->blanked(explode("\n", $contents)) as $line) {
            if (1 === preg_match(self::NEXT_SECTION, $line)) {
                $inSection = self::SECTION === rtrim($line);

                continue;
            }

            if ($inSection) {
                $section[] = $line;
            }
        }

        return $this->tableBodyRows($section);
    }

    // Only a table body row counts, because the section's prose names `upstream/<slug>/` as a pattern.
    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function tableBodyRows(array $lines): array
    {
        $rows = [];
        $columns = 0;
        $previous = '';

        foreach ($lines as $line) {
            // A line without a cell border ends the table even where GFM keeps it as a row, because that can only fail closed.
            if ($columns > 0 && $this->isATableRow($line)) {
                $rows[] = implode('|', array_slice($this->cells($line), 0, $columns));
            } else {
                $columns = $this->startsATable($previous, $line) ? count($this->cells($previous)) : 0;
            }

            $previous = $line;
        }

        return $rows;
    }

    private function isATableRow(string $line): bool
    {
        return 1 === preg_match(self::CELL_BORDER, $line) && 0 === preg_match(self::BLOCK_START, $line);
    }

    /**
     * @return list<string>
     */
    private function cells(string $row): array
    {
        $cells = preg_split(self::CELL_BORDER, preg_replace(self::OUTER_BORDERS, '', $row) ?? '');

        return false === $cells ? [] : $cells;
    }

    private function startsATable(string $header, string $delimiter): bool
    {
        return 0 === preg_match(self::INDENTED_CODE, $header)
            && $this->isATableRow($header)
            && 1 === preg_match(self::CELL_BORDER, $delimiter)
            && 1 === preg_match(self::DELIMITER_ROW, $delimiter);
    }
}
