<?php

declare(strict_types=1);

namespace Intakt\Gates;

use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Clock\ClockInterface;

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

    private const int MAX_CHECK_AGE_DAYS = 14;

    private const string READY_STATUS = '/^- \*\*(?:Decision|Status):\*\*\s*`(?<value>[^`]*)`/';

    private const string CHECKED_LINE = '/^- \*\*Checked:\*\*\s*(?<date>\S+)/m';

    public function __construct(
        private FencedCodeBlocks $codeBlocks,
        private ClockInterface $clock,
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

        $findings = [...$findings, ...$this->uncheckedReports($workspace, $folders)];

        return [] === $findings ? GateResult::passed($this->name()) : GateResult::withFindings($this->name(), $findings);
    }

    /**
     * @param list<string> $folders
     *
     * @return list<string>
     */
    private function uncheckedReports(Workspace $workspace, array $folders): array
    {
        $findings = [];

        foreach ($folders as $folder) {
            $report = $workspace->root.'/upstream/'.$folder.'/REPORT.md';
            $contents = is_file($report) ? file_get_contents($report) : false;

            if (false === $contents) {
                continue;
            }

            $lines = $this->codeBlocks->blanked(explode("\n", $contents));

            if ($this->readyToFile($lines)) {
                $finding = $this->checkFinding(implode("\n", $lines));

                if (null !== $finding) {
                    $findings[] = 'upstream/'.$folder.'/REPORT.md is ready to file '.$finding;
                }
            }
        }

        return $findings;
    }

    // The last Decision or Status line decides, because a later `not filed` line retires an earlier `ready to file`.
    /**
     * @param list<string> $lines
     */
    private function readyToFile(array $lines): bool
    {
        $current = null;

        foreach ($lines as $line) {
            if (1 === preg_match(self::READY_STATUS, $line, $match)) {
                $current = $match['value'];
            }
        }

        return 'ready to file' === $current;
    }

    private function checkFinding(string $report): ?string
    {
        if (1 !== preg_match(self::CHECKED_LINE, $report, $match)) {
            return 'but has no "Checked" line (date, upstream commit or tag, duplicate search, preferred form and target branch)';
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $match['date'], new DateTimeZone('UTC'));

        if (false === $date || $date->format('Y-m-d') !== $match['date']) {
            return 'but its "Checked" line does not start with a date as YYYY-MM-DD';
        }

        $age = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0)->diff($date);

        if ($age->invert < 1 && $age->days > 0) {
            return 'but its "Checked" line is dated in the future: '.$match['date'];
        }

        return $age->invert > 0 && $age->days > self::MAX_CHECK_AGE_DAYS
            ? 'but its "Checked" line is older than '.self::MAX_CHECK_AGE_DAYS.' days ('.$match['date'].'): check it against upstream again before posting'
            : null;
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

    private function startsATable(string $header, string $delimiter): bool
    {
        return 0 === preg_match(self::INDENTED_CODE, $header)
            && $this->isATableRow($header)
            && 1 === preg_match(self::CELL_BORDER, $delimiter)
            && 1 === preg_match(self::DELIMITER_ROW, $delimiter);
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
}
