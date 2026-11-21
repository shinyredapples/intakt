<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\BenchHeadline;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// Read from the README's review bench table, the one place the published run is kept, so the tile changes with the next published run and never carries a copied figure.
final readonly class PublishedBench
{
    private const string SECTION_HEADING = '/^### Review bench[ \t]*$/';
    // Any heading of level 1 to 3, ATX or Setext, ends the section, because the tables of later sections must never be read as the bench.
    private const string LATER_HEADING = '/^ {0,3}#{1,3}(?:[ \t]|$)/';
    private const string SETEXT_UNDERLINE = '/^ {0,3}(?:=+|-+)[ \t]*$/';
    private const string FENCE = '/^ {0,3}(`{3,}|~{3,})/';
    private const string TABLE_LINE = '/^ {0,3}\|/';
    private const string TABLE_ROW = '/^\|(.*)(?<!\\\)\|$/';
    private const string CELL_BOUNDARY = '/(?<!\\\)\|/';
    private const string DELIMITER_CELL = '/^:?-+:?$/';
    // Only the unit the bench measures in, so a column of another unit, such as per change, is never shown as a total.
    private const string FOUND_COLUMN = '/^Defects found(?: \(measured\))?$/';
    private const string FALSE_ALARMS_COLUMN = '/^False alarms(?: \(measured\))?$/';
    private const string PANEL_CELL = '/^Panel\b/';
    private const string FOUND_CELL = '/^(\d+) of (\d+)$/';
    private const string COUNT_CELL = '/^\d+$/';
    private const string RUN_DATE = '/^Date of the run: ((\d{4})-(\d{2})-(\d{2}))\./';

    public function __construct(
        #[Autowire('%kernel.project_dir%/README.md')]
        private string $readme,
    ) {
    }

    // Null when the README or its table is missing or changed shape, so the page shows no bench tile rather than a wrong one.
    public function headline(): ?BenchHeadline
    {
        $section = $this->benchSection();
        if (null === $section) {
            return null;
        }

        $table = $this->onlyTable($section);
        if (null === $table || !$this->namesTheColumns($table[0])) {
            return null;
        }

        $panel = $this->panelResult(array_slice($table, 2));
        $ranOn = $this->runDate($section);
        if (null === $panel || null === $ranOn) {
            return null;
        }

        return new BenchHeadline($panel[0], $panel[1], $panel[2], $ranOn);
    }

    /**
     * @return list<string>|null
     */
    private function benchSection(): ?array
    {
        $lines = $this->linesOutsideFences();
        $starts = array_keys(preg_grep(self::SECTION_HEADING, $lines) ?: []);
        if (1 !== count($starts)) {
            return null;
        }

        $section = [];
        $previous = '';
        foreach (array_slice($lines, $starts[0] + 1) as $line) {
            if (1 === preg_match(self::LATER_HEADING, $line) || ('' !== trim($previous) && 1 === preg_match(self::SETEXT_UNDERLINE, $line))) {
                break;
            }

            $section[] = $line;
            $previous = $line;
        }

        return $section;
    }

    // Fenced lines become blank, so an example in a code block never supplies the section, its table or its run date.
    /**
     * @return list<string>
     */
    private function linesOutsideFences(): array
    {
        $text = is_file($this->readme) && is_readable($this->readme) ? (string) file_get_contents($this->readme) : '';
        $lines = [];
        $closing = null;
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (null === $closing && 1 === preg_match(self::FENCE, $line, $fence)) {
                $closing = sprintf('/^ {0,3}%s{%d,}[ \t]*$/', preg_quote($fence[1][0], '/'), strlen($fence[1]));
                $line = '';
            } elseif (null !== $closing) {
                $closing = 1 === preg_match($closing, $line) ? null : $closing;
                $line = '';
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @param list<string> $section
     *
     * @return list<list<string>>|null the header, the delimiter and the body rows of the section's one table
     */
    private function onlyTable(array $section): ?array
    {
        $tables = [];
        $inTable = false;
        foreach ($section as $line) {
            $isRow = 1 === preg_match(self::TABLE_LINE, $line);
            if ($isRow && !$inTable) {
                $tables[] = [];
            }
            if ($isRow) {
                $tables[count($tables) - 1][] = $line;
            }
            $inTable = $isRow;
        }

        if (1 !== count($tables)) {
            return null;
        }

        $rows = [];
        foreach ($tables[0] as $line) {
            $cells = $this->cells($line);
            if (null === $cells || (isset($rows[0]) && count($cells) !== count($rows[0]))) {
                return null;
            }
            $rows[] = $cells;
        }

        return count($rows) >= 3 && [] === array_filter($rows[1], static fn (string $cell): bool => 1 !== preg_match(self::DELIMITER_CELL, $cell))
            ? $rows
            : null;
    }

    /**
     * @return list<string>|null
     */
    private function cells(string $line): ?array
    {
        if (1 !== preg_match(self::TABLE_ROW, trim($line), $row)) {
            return null;
        }

        return array_map(trim(...), preg_split(self::CELL_BOUNDARY, $row[1]) ?: []);
    }

    /**
     * @param list<string> $header
     */
    private function namesTheColumns(array $header): bool
    {
        return 1 === preg_match(self::FOUND_COLUMN, $header[1] ?? '')
            && 1 === preg_match(self::FALSE_ALARMS_COLUMN, $header[2] ?? '');
    }

    /**
     * @param list<list<string>> $body
     *
     * @return array{int, int, int}|null
     */
    private function panelResult(array $body): ?array
    {
        $panels = array_values(array_filter($body, static fn (array $row): bool => 1 === preg_match(self::PANEL_CELL, $row[0])));
        if (1 !== count($panels)
            || 1 !== preg_match(self::FOUND_CELL, $panels[0][1] ?? '', $found)
            || 1 !== preg_match(self::COUNT_CELL, $panels[0][2] ?? '')) {
            return null;
        }

        [$defects, $planted, $falseAlarms] = [$this->countOf($found[1]), $this->countOf($found[2]), $this->countOf($panels[0][2])];
        if (null === $defects || null === $planted || null === $falseAlarms || 0 === $planted || $defects > $planted) {
            return null;
        }

        return [$defects, $planted, $falseAlarms];
    }

    // A count beyond the integer range is refused rather than clamped, so the tile never shows a figure the README does not hold.
    private function countOf(string $digits): ?int
    {
        $count = filter_var($digits, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return is_int($count) ? $count : null;
    }

    /**
     * @param list<string> $section
     */
    private function runDate(array $section): ?string
    {
        $dates = array_values(array_filter(array_map(
            static fn (string $line): ?array => 1 === preg_match(self::RUN_DATE, $line, $date) ? $date : null,
            $section,
        )));
        if (1 !== count($dates)) {
            return null;
        }

        [, $date, $year, $month, $day] = $dates[0];

        return checkdate((int) $month, (int) $day, (int) $year) ? $date : null;
    }
}
