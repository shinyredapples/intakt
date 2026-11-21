<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\BenchHeadline;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// Read from the README's review bench table, the one place the published run is kept, so the tile changes with the next published run and never carries a copied figure.
final readonly class PublishedBench
{
    // Up to the next heading of level 1 to 3, because the tables of later sections must never be read as the bench.
    private const string SECTION = '/^### Review bench[ \t]*$(.*?)(?=^#{1,3} |\z)/ms';
    private const string FOUND_COLUMN = '/^Defects found(?: \([^)]*\))?$/';
    private const string FALSE_ALARMS_COLUMN = '/^False alarms(?: \([^)]*\))?$/';
    private const string TABLE_ROW = '/^\|.*\|[ \t]*$/m';
    private const string FOUND_CELL = '/^(\d+) of (\d+)$/';
    private const string COUNT_CELL = '/^\d+$/';
    private const string RUN_DATE = '/^Date of the run: ((\d{4})-(\d{2})-(\d{2}))\./m';

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

        $rows = $this->tableRows($section);
        if (!$this->namesTheColumns($rows[0] ?? [])) {
            return null;
        }

        $panel = $this->panelRow($rows);
        $ranOn = $this->runDate($section);
        if (null === $panel || null === $ranOn) {
            return null;
        }

        return new BenchHeadline($panel[0], $panel[1], $panel[2], $ranOn);
    }

    private function benchSection(): ?string
    {
        $text = is_file($this->readme) ? (string) file_get_contents($this->readme) : '';

        return 1 === preg_match(self::SECTION, $text, $section) ? $section[1] : null;
    }

    /**
     * @return list<list<string>>
     */
    private function tableRows(string $section): array
    {
        preg_match_all(self::TABLE_ROW, $section, $lines);

        return array_map(
            static fn (string $line): array => array_map(trim(...), explode('|', trim($line, " \t|"))),
            $lines[0],
        );
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
     * @param list<list<string>> $rows
     *
     * @return array{int, int, int}|null
     */
    private function panelRow(array $rows): ?array
    {
        $panels = array_values(array_filter($rows, static fn (array $row): bool => 1 === preg_match('/^Panel\b/', $row[0])));
        if (1 !== count($panels)
            || 1 !== preg_match(self::FOUND_CELL, $panels[0][1] ?? '', $found)
            || 1 !== preg_match(self::COUNT_CELL, $panels[0][2] ?? '')) {
            return null;
        }

        return [(int) $found[1], (int) $found[2], (int) $panels[0][2]];
    }

    private function runDate(string $section): ?string
    {
        if (1 !== preg_match_all(self::RUN_DATE, $section, $dates, PREG_SET_ORDER)) {
            return null;
        }

        [, $date, $year, $month, $day] = $dates[0];

        return checkdate((int) $month, (int) $day, (int) $year) ? $date : null;
    }
}
