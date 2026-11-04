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

    public function __construct(
        private FencedCodeBlocks $codeBlocks,
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

        $contents = is_file($workspace->root.'/'.self::HIGHLIGHTS) ? file_get_contents($workspace->root.'/'.self::HIGHLIGHTS) : '';

        if (false === $contents) {
            return GateResult::failedBecause($this->name(), 'cannot read '.self::HIGHLIGHTS);
        }

        $findings = array_map(
            static fn (string $folder): string => 'upstream/'.$folder.'/ has no row in "Found in the beta" of '.self::HIGHLIGHTS,
            array_values(array_diff($folders, $this->foldersWithARow($contents))),
        );

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
        $inTable = false;
        $previous = '';

        foreach ($lines as $line) {
            // A table ends at the first line without a cell border (GFM: a blank line or another block), as in HighlightsGate.
            if ($inTable && $this->isATableRow($line)) {
                $rows[] = $line;
            } else {
                $inTable = $this->startsATable($previous, $line);
            }

            $previous = $line;
        }

        return $rows;
    }

    private function startsATable(string $header, string $delimiter): bool
    {
        return $this->isATableRow($header) && $this->isATableRow($delimiter) && 1 === preg_match(self::DELIMITER_ROW, $delimiter);
    }

    private function isATableRow(string $line): bool
    {
        return 1 === preg_match(self::CELL_BORDER, $line);
    }
}
